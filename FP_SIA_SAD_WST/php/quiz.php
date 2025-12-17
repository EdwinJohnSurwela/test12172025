<?php
session_start();

// Clear any error flags
unset($_SESSION['_clear_on_next_load']);

require_once 'config.php';

check_user_type(['student']);

$scanned_book = $_SESSION['scanned_book'] ?? null;

if (!$scanned_book) {
    header("Location: index.php");
    exit();
}

// Extract book details
$book_title = $scanned_book['title'];
$book_author = $scanned_book['author'];
$book_id = $scanned_book['bookId'];

// Safely get session values WITH VALIDATION
$user_id = $_SESSION['user_id'] ?? 0;
$student_name = $_SESSION['full_name'] ?? 'Unknown';
$student_id = $_SESSION['student_id'] ?? 'N/A';

// Check if book has a max attempts limit and whether this student exceeded it
$book_limit_sql = "SELECT max_quiz_attempts FROM books WHERE book_id = ?";
$book_limit_stmt = $conn->prepare($book_limit_sql);
if ($book_limit_stmt) {
    $book_limit_stmt->bind_param("i", $book_id);
    $book_limit_stmt->execute();
    $book_limit_result = $book_limit_stmt->get_result();
    $book_limit_data = $book_limit_result->fetch_assoc();
    $max_attempts = array_key_exists('max_quiz_attempts', $book_limit_data) && $book_limit_data['max_quiz_attempts'] !== null ? (int)$book_limit_data['max_quiz_attempts'] : null;
    $book_limit_stmt->close();

    if ($max_attempts !== null) {
        $count_sql = "SELECT COUNT(*) as attempts FROM quiz_attempts WHERE user_id = ? AND book_id = ?";
        $count_stmt = $conn->prepare($count_sql);
        if ($count_stmt) {
            $count_stmt->bind_param("ii", $user_id, $book_id);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            $count_row = $count_result->fetch_assoc();
            $previous_attempts = (int)($count_row['attempts'] ?? 0);
            $count_stmt->close();

            if ($previous_attempts >= $max_attempts) {
                // Render a friendly message page and stop execution
                echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Quiz Locked</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font-family:Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#222;display:flex;align-items:center;justify-content:center;height:100vh;margin:0} .card{background:white;padding:30px;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.12);max-width:560px;text-align:center} h1{color:#333} p{color:#555} a.btn{display:inline-block;margin-top:18px;padding:10px 16px;background:#1a4480;color:#fff;border-radius:8px;text-decoration:none}</style></head><body><div class="card"><h1>Attempts Exhausted</h1><p>You have reached the maximum allowed attempts (' . htmlspecialchars((string)$max_attempts) . ') for this quiz.</p><a class="btn" href="index.php">Return to Library</a></div></body></html>';
                exit;
            }
        }
    }
}

// Safely get session values WITH VALIDATION
$user_id = $_SESSION['user_id'] ?? 0;
$student_name = $_SESSION['full_name'] ?? 'Unknown';
$student_id = $_SESSION['student_id'] ?? 'N/A';

// SECURITY FIX: Verify user exists and is active
if ($user_id <= 0) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$user_verify_sql = "SELECT user_id, full_name, status FROM users WHERE user_id = ? AND user_type = 'student'";
$user_verify_stmt = $conn->prepare($user_verify_sql);
$user_verify_stmt->bind_param("i", $user_id);
$user_verify_stmt->execute();
$user_verify_result = $user_verify_stmt->get_result();

if ($user_verify_result->num_rows === 0) {
    $user_verify_stmt->close();
    session_destroy();
    die("Invalid user session. Please <a href='login.php'>login again</a>.");
}

$user_verify_data = $user_verify_result->fetch_assoc();
if ($user_verify_data['status'] !== 'active') {
    $user_verify_stmt->close();
    session_destroy();
    die("Your account is inactive. Please contact the administrator.");
}
$user_verify_stmt->close();

// FIXED: Count books completed - ANY attempt counts as completing a book
$progress_sql = "SELECT 
                    COUNT(DISTINCT qa.book_id) as books_completed,
                    COALESCE(AVG(qa.score_percentage), 0) as average_score
                 FROM quiz_attempts qa
                 WHERE qa.user_id = ?";
$progress_stmt = $conn->prepare($progress_sql);
$progress_stmt->bind_param("i", $user_id);
$progress_stmt->execute();
$progress_result = $progress_stmt->get_result();
$progress_data = $progress_result->fetch_assoc();

$books_completed = (int)($progress_data['books_completed'] ?? 0);
$average_score = round((float)($progress_data['average_score'] ?? 0), 1);

$progress_stmt->close();

// Get rewards earned count
$rewards_count_sql = "SELECT COUNT(DISTINCT reward_id) as total FROM user_rewards WHERE user_id = ?";
$rewards_count_stmt = $conn->prepare($rewards_count_sql);
$rewards_count_stmt->bind_param("i", $user_id);
$rewards_count_stmt->execute();
$rewards_count_result = $rewards_count_stmt->get_result();
$rewards_count_data = $rewards_count_result->fetch_assoc();
$rewards_earned = (int)($rewards_count_data['total'] ?? 0);
$rewards_count_stmt->close();

$rewards_sql = "SELECT r.reward_id, r.reward_name, r.reward_description, r.books_required, r.reward_type,
                       CASE WHEN ur.user_reward_id IS NOT NULL THEN TRUE ELSE FALSE END as is_earned
                FROM rewards r
                LEFT JOIN user_rewards ur ON r.reward_id = ur.reward_id AND ur.user_id = ?
                WHERE r.is_active = TRUE
                ORDER BY r.books_required ASC";
$rewards_stmt = $conn->prepare($rewards_sql);
$rewards_stmt->bind_param("i", $user_id);
$rewards_stmt->execute();
$rewards_result = $rewards_stmt->get_result();
$all_rewards = [];
while ($row = $rewards_result->fetch_assoc()) {
    $all_rewards[] = $row;
}
$rewards_stmt->close();

// UPDATED: Fetch ALL questions for this book, then randomize and limit to max 100
$questions_sql = "SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_answer
                  FROM quiz_questions 
                  WHERE book_id = ? 
                  ORDER BY RAND()";
$stmt = $conn->prepare($questions_sql);
$stmt->bind_param("i", $book_id);
$stmt->execute();
$questions_result = $stmt->get_result();

$all_questions = [];
while ($row = $questions_result->fetch_assoc()) {
    $all_questions[] = $row;
}
$stmt->close();

// Get quiz time limit from books table
$timer_sql = "SELECT COALESCE(quiz_time_limit, 0) as quiz_time_limit FROM books WHERE book_id = ?";
$timer_stmt = $conn->prepare($timer_sql);
$timer_stmt->bind_param("i", $book_id);
$timer_stmt->execute();
$timer_result = $timer_stmt->get_result();
$timer_data = $timer_result->fetch_assoc();
$quiz_time_limit = (int)($timer_data['quiz_time_limit'] ?? 0);
$timer_stmt->close();

// Limit to maximum 100 questions (already randomized by SQL)
$max_questions = 100;
$questions = array_slice($all_questions, 0, $max_questions);

if (empty($questions)) {
    die("No quiz questions found for this book. Please contact the librarian.");
}

$total_questions = count($questions);
$total_available = count($all_questions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz - <?php echo htmlspecialchars($book_title); ?> | Library Hub</title>
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Error Handler -->
    <script src="../js/error-handler.js"></script>
    <style>
        :root {
            /* DepEd Color Scheme */
            --deped-blue: #1a4480;
            --deped-blue-dark: #0d2240;
            --deped-red: #c41230;
            --deped-red-dark: #8b0a1e;
            --main-gradient: linear-gradient(135deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
            --btn-gradient: linear-gradient(180deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--main-gradient);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 25px;
        }

        .header h1 {
            font-size: 2em;
            margin-bottom: 8px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .header p {
            font-size: 1.1em;
            opacity: 0.95;
        }

        /* Stats Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: rgba(255,255,255,0.95);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .stat-card .stat-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: #667eea;
        }

        .stat-card .stat-label {
            font-size: 13px;
            color: #666;
            font-weight: 600;
        }

        /* Quiz Card */
        .quiz-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.2);
            margin-bottom: 25px;
        }

        .book-info {
            background: linear-gradient(135deg, #f8f9ff 0%, #fff 100%);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 5px solid #667eea;
        }

        .book-info h2 {
            color: #333;
            font-size: 1.4em;
            margin-bottom: 5px;
        }

        .book-info p {
            color: #666;
            font-size: 14px;
        }

        /* Progress Section */
        .progress-section {
            margin-bottom: 25px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .progress-text {
            font-weight: 700;
            color: #333;
        }

        .progress-bar {
            height: 12px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--main-gradient);
            border-radius: 10px;
            transition: width 0.4s ease;
            width: 0%;
        }

        /* Question Navigation Toggle */
        .question-nav-toggle {
            background: #f8f9fa;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            color: #555;
            width: 100%;
            text-align: left;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .question-nav-toggle:hover {
            background: #e9ecef;
        }

        .question-nav-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease;
            margin-bottom: 20px;
        }

        .question-nav-container.open {
            max-height: 500px;
            overflow-y: auto;
        }

        .question-nav-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(45px, 1fr));
            gap: 8px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
        }

        .question-nav-btn {
            width: 100%;
            aspect-ratio: 1;
            border: 2px solid #ddd;
            background: white;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
        }

        .question-nav-btn:hover {
            border-color: #667eea;
            transform: scale(1.05);
        }

        .question-nav-btn.current {
            background: var(--main-gradient);
            color: white;
            border-color: transparent;
        }

        .question-nav-btn.answered {
            background: #28a745;
            color: white;
            border-color: transparent;
        }

        .nav-summary {
            display: flex;
            gap: 20px;
            padding: 10px 15px;
            font-size: 13px;
            color: #666;
        }

        .nav-summary span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-summary .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .nav-summary .dot.answered { background: #28a745; }
        .nav-summary .dot.current { background: var(--main-gradient); }
        .nav-summary .dot.unanswered { background: #ddd; }

        /* Question Container */
        .question-container {
            background: #fafbfc;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            min-height: 280px;
            position: relative;
            perspective: 1000px;
        }

        .question-container h4 {
            font-size: 1.2em;
            color: #333;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .options label {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 18px;
            background: white;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 15px;
        }

        .options label:hover {
            border-color: #667eea;
            background: #f8f9ff;
            transform: translateX(5px);
        }

        .options input[type="radio"] {
            width: 20px;
            height: 20px;
            accent-color: #667eea;
        }

        .options input[type="radio"]:checked + span,
        .options label:has(input:checked) {
            font-weight: 600;
        }

        .options label:has(input:checked) {
            border-color: #667eea;
            background: linear-gradient(135deg, #f0f4ff 0%, #fff 100%);
        }

        /* Navigation Buttons */
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--btn-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn-warning {
            background: #ffc107;
            color: #333;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Results Section */
        .quiz-results {
            display: none;
            text-align: center;
            padding: 40px 20px;
        }

        .result-score {
            font-size: 72px;
            font-weight: 800;
            background: var(--main-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }

        .result-percentage {
            font-size: 28px;
            color: #666;
            margin-bottom: 30px;
        }

        .result-message {
            font-size: 18px;
            color: #333;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
        }

        .rewards-section {
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #fff9e6 0%, #fff 100%);
            border-radius: 15px;
            border: 2px solid #ffc107;
        }

        .rewards-section h3 {
            color: #333;
            margin-bottom: 15px;
        }

        .reward-item {
            display: inline-block;
            padding: 10px 20px;
            background: var(--main-gradient);
            color: white;
            border-radius: 25px;
            margin: 5px;
            font-weight: 600;
            animation: rewardPop 0.5s ease;
        }

        @keyframes rewardPop {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        /* Tab Warning Modal */
        .tab-warning-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 99999;
        }

        .tab-warning-overlay.show {
            display: flex;
        }

        .tab-warning-modal {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 450px;
            text-align: center;
            animation: modalShake 0.5s ease;
        }

        @keyframes modalShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-10px); }
            40%, 80% { transform: translateX(10px); }
        }

        @keyframes timerPulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }

        .tab-warning-modal .warning-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .tab-warning-modal h2 {
            color: #dc3545;
            margin-bottom: 15px;
        }

        .tab-warning-modal p {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .warning-count {
            display: inline-block;
            padding: 8px 20px;
            background: #dc3545;
            color: white;
            border-radius: 25px;
            font-weight: 700;
            margin-bottom: 25px;
        }

        /* Fill in the Blank Styles */
        .fill-blank-input {
            width: 100%;
            padding: 15px 18px;
            font-size: 16px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            margin-top: 15px;
            transition: all 0.3s ease;
        }

        .fill-blank-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        .fill-blank-input::placeholder {
            color: #aaa;
        }

        /* Identification Styles */
        .identification-input {
            width: 100%;
            padding: 15px 18px;
            font-size: 16px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            margin-top: 15px;
            transition: all 0.3s ease;
        }

        .identification-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        /* Enumeration Styles */
        .enumeration-container {
            margin-top: 15px;
        }

        .enumeration-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .enumeration-items {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .enumeration-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .enumeration-item .item-number {
            width: 30px;
            height: 30px;
            background: var(--main-gradient);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .enumeration-item input {
            flex: 1;
            padding: 12px 15px;
            font-size: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .enumeration-item input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        /* Question Type Badge */
        .question-type-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
            text-transform: uppercase;
        }

        .question-type-badge.multiple_choice {
            background: #e3f2fd;
            color: #1976d2;
        }

        .question-type-badge.fill_blank {
            background: #fff3e0;
            color: #f57c00;
        }

        .question-type-badge.identification {
            background: #e8f5e9;
            color: #388e3c;
        }

        .question-type-badge.enumeration {
            background: #fce4ec;
            color: #c2185b;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
            }

            .nav-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .question-nav-grid {
                grid-template-columns: repeat(auto-fill, minmax(40px, 1fr));
            }
            
            .enumeration-item {
                flex-direction: column;
                align-items: stretch;
            }
            
            .enumeration-item .item-number {
                align-self: flex-start;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📚 Library Hub Quiz</h1>
            <p>Welcome, <?php echo htmlspecialchars($student_name); ?>!</p>
        </div>

        <!-- Stats Cards -->
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon">📖</div>
                <div class="stat-value" id="booksCompletedStat"><?php echo $books_completed; ?></div>
                <div class="stat-label">Books Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value" id="averageScoreStat"><?php echo $average_score; ?>%</div>
                <div class="stat-label">Average Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🏆</div>
                <div class="stat-value" id="rewardsEarnedStat"><?php echo $rewards_earned; ?></div>
                <div class="stat-label">Rewards Earned</div>
            </div>
        </div>

        <!-- Quiz Card -->
        <div class="quiz-card">
            <!-- Book Info -->
            <div class="book-info">
                <h2>📖 <?php echo htmlspecialchars($book_title); ?></h2>
                <p>by <?php echo htmlspecialchars($book_author); ?></p>
                <?php if ($total_available > $max_questions): ?>
                <p style="margin-top: 8px; color: #667eea; font-weight: 600;">
                    📝 <?php echo $total_questions; ?> randomized questions from <?php echo $total_available; ?> available
                </p>
                <?php endif; ?>
            </div>

            <?php if ($quiz_time_limit > 0): ?>
            <!-- Quiz Timer -->
            <div id="quizTimerContainer" style="background: linear-gradient(135deg, #1a4480 0%, #0d2240 100%); padding: 15px 25px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; justify-content: center; gap: 15px;">
                <span style="color: white; font-size: 18px;">⏱️</span>
                <div id="quizTimer" style="font-family: 'Courier New', monospace; font-size: 28px; font-weight: 700; color: white; letter-spacing: 2px;">
                    00:00:00
                </div>
                <span id="timerLabel" style="color: rgba(255,255,255,0.8); font-size: 14px;">Time Remaining</span>
            </div>
            <?php endif; ?>

            <!-- Quiz Content -->
            <div id="quizContent">
                <!-- Progress Section -->
                <div class="progress-section">
                    <div class="progress-header">
                        <span class="progress-text">Question <span id="currentQuestion">1</span> of <?php echo $total_questions; ?></span>
                        <span class="progress-text"><span id="answeredDisplay">0</span> answered</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                </div>

                <!-- Question Navigation Toggle -->
                <button class="question-nav-toggle" onclick="toggleQuestionNav()">
                    <span id="navToggleText">📋 Show All Questions</span>
                    <span>▼</span>
                </button>

                <!-- Question Navigation Container -->
                <div class="question-nav-container" id="questionNavContainer">
                    <div class="nav-summary">
                        <span><span class="dot answered"></span> Answered: <strong id="answeredCount">0</strong></span>
                        <span><span class="dot unanswered"></span> Unanswered: <strong id="unansweredCount"><?php echo $total_questions; ?></strong></span>
                    </div>
                    <div class="question-nav-grid" id="questionNavGrid"></div>
                </div>

                <!-- Question Container -->
                <div class="question-container" id="questionContainer">
                    <!-- Questions loaded here by JS -->
                </div>

                <!-- Navigation Buttons -->
                <div class="nav-buttons">
                    <button class="btn btn-secondary" id="prevBtn" onclick="previousQuestion()" style="display: none;">
                        ← Previous
                    </button>
                    <button class="btn btn-warning" id="skipBtn" onclick="skipQuestion()">
                        Skip →
                    </button>
                    <button class="btn btn-primary" id="nextBtn" onclick="nextQuestion()">
                        Next →
                    </button>
                    <button class="btn btn-success" id="submitBtn" onclick="submitQuiz()" style="display: none;">
                        ✅ Submit Quiz
                    </button>
                </div>
            </div>

            <!-- Results Section -->
            <div class="quiz-results" id="quizResults">
                <div class="result-score" id="finalScore">0/0</div>
                <div class="result-percentage" id="percentage">0%</div>
                <div class="result-message" id="resultMessage">
                    Great job completing the quiz!
                </div>

                <div class="rewards-section" id="rewardsEarnedSection" style="display: none;">
                    <h3>🎉 New Rewards Unlocked!</h3>
                    <div id="newRewardsContainer"></div>
                </div>

                <button class="btn btn-primary" id="scanAnotherBtn" style="margin-top: 25px;">
                    📱 Scan Another Book
                </button>
            </div>
        </div>
    </div>

    <!-- Tab Warning Modal -->
    <div class="tab-warning-overlay" id="tabWarningOverlay">
        <div class="tab-warning-modal">
            <div class="warning-icon">⚠️</div>
            <h2>Tab Switch Detected!</h2>
            <p>You have switched away from the quiz. Please stay on this page to complete your quiz fairly.</p>
            <div class="warning-count" id="warningCountDisplay">Warning 1 of 3</div>
            <p id="warningMessage">After 3 warnings, your quiz will be automatically submitted.</p>
            <button class="btn btn-primary" onclick="dismissTabWarning()">I Understand, Continue Quiz</button>
        </div>
    </div>

    <script>
        // ==================== DATA FROM PHP ====================
        const questionsData = <?php echo json_encode($questions, JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const PHP_BOOK_ID = <?php echo $book_id; ?>;
        const PHP_USER_ID = <?php echo $user_id; ?>;
        const PHP_BOOK_TITLE = '<?php echo addslashes($book_title); ?>';
        const PHP_TOTAL_QUESTIONS = <?php echo $total_questions; ?>;
        const QUIZ_TIME_LIMIT = <?php echo $quiz_time_limit; ?>; // Time limit in seconds (0 = no limit)

        // ==================== STATE VARIABLES ====================
        let currentQuestionIndex = 0;
        let score = 0;
        let userAnswers = new Array(questionsData.length).fill(null);
        let quizStartTime = Date.now();
        let tabSwitchCount = 0;
        const MAX_TAB_WARNINGS = 3;
        let quizSubmitted = false;
        let tabSwitchLog = [];
        let timerInterval = null;
        let remainingTime = QUIZ_TIME_LIMIT;
        let timeExpired = false;

        // ==================== QUIZ TIMER ====================
        function initQuizTimer() {
            if (QUIZ_TIME_LIMIT <= 0) return; // No timer if no limit
            
            remainingTime = QUIZ_TIME_LIMIT;
            updateTimerDisplay();
            
            timerInterval = setInterval(() => {
                if (quizSubmitted) {
                    clearInterval(timerInterval);
                    return;
                }
                
                remainingTime--;
                updateTimerDisplay();
                
                // Warning at 5 minutes
                if (remainingTime === 300) {
                    showTimerWarning('⏱️ 5 minutes remaining!', '#ffc107');
                }
                
                // Warning at 1 minute
                if (remainingTime === 60) {
                    showTimerWarning('⚠️ 1 minute remaining!', '#ff6b6b');
                }
                
                // Warning at 30 seconds
                if (remainingTime === 30) {
                    showTimerWarning('🚨 30 seconds remaining!', '#dc3545');
                }
                
                // Time's up - auto submit
                if (remainingTime <= 0) {
                    clearInterval(timerInterval);
                    timeExpired = true;
                    autoSubmitDueToTimeout();
                }
            }, 1000);
        }
        
        function updateTimerDisplay() {
            const timerEl = document.getElementById('quizTimer');
            const containerEl = document.getElementById('quizTimerContainer');
            if (!timerEl) return;
            
            const hours = Math.floor(remainingTime / 3600);
            const minutes = Math.floor((remainingTime % 3600) / 60);
            const seconds = remainingTime % 60;
            
            timerEl.textContent = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            
            // Change color based on time remaining
            if (remainingTime <= 60) {
                containerEl.style.background = 'linear-gradient(135deg, #dc3545 0%, #8b0a1e 100%)';
                timerEl.style.animation = 'timerPulse 0.5s ease-in-out infinite';
            } else if (remainingTime <= 300) {
                containerEl.style.background = 'linear-gradient(135deg, #ff6b6b 0%, #c41230 100%)';
            }
        }
        
        function showTimerWarning(message, color) {
            Swal.fire({
                title: message,
                icon: 'warning',
                background: color,
                color: 'white',
                showConfirmButton: false,
                timer: 2000,
                position: 'top'
            });
        }
        
        function autoSubmitDueToTimeout() {
            if (quizSubmitted) return;
            quizSubmitted = true;
            
            Swal.fire({
                title: '⏰ Time\'s Up!',
                html: `<p style="font-size: 16px;">Your quiz time has expired.</p>
                       <p>Your answers are being submitted automatically.</p>`,
                icon: 'warning',
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                timer: 3000
            }).then(() => {
                submitQuizWithFlag(false, true); // false = not flagged for tab switch, true = time expired
            });
        }

        // ==================== TAB SWITCH DETECTION ====================
        function initTabSwitchDetection() {
            // Visibility change detection
            document.addEventListener('visibilitychange', handleVisibilityChange);
            
            // Window blur detection (catches more cases)
            window.addEventListener('blur', handleWindowBlur);
            
            // Focus detection to log returns
            window.addEventListener('focus', handleWindowFocus);
        }

        function handleVisibilityChange() {
            if (document.hidden && !quizSubmitted) {
                recordTabSwitch('visibility_hidden');
            }
        }

        function handleWindowBlur() {
            if (!quizSubmitted) {
                recordTabSwitch('window_blur');
            }
        }

        function handleWindowFocus() {
            // Log when user returns
            if (tabSwitchCount > 0 && !quizSubmitted) {
                tabSwitchLog.push({
                    type: 'returned',
                    timestamp: new Date().toISOString(),
                    question: currentQuestionIndex + 1
                });
            }
        }

        function recordTabSwitch(type) {
            tabSwitchCount++;
            
            tabSwitchLog.push({
                type: type,
                timestamp: new Date().toISOString(),
                question: currentQuestionIndex + 1,
                warning_number: tabSwitchCount
            });

            console.log(`[Quiz] Tab switch detected: ${type}, Warning ${tabSwitchCount}/${MAX_TAB_WARNINGS}`);

            if (tabSwitchCount > MAX_TAB_WARNINGS) {
                // Auto-submit the quiz
                autoSubmitDueToTabSwitch();
            } else {
                // Show warning modal
                showTabWarning();
            }
        }

        function showTabWarning() {
            const overlay = document.getElementById('tabWarningOverlay');
            const countDisplay = document.getElementById('warningCountDisplay');
            const message = document.getElementById('warningMessage');

            countDisplay.textContent = `Warning ${tabSwitchCount} of ${MAX_TAB_WARNINGS}`;

            if (tabSwitchCount === MAX_TAB_WARNINGS) {
                message.innerHTML = '<strong style="color: #dc3545;">⚠️ FINAL WARNING! One more tab switch will auto-submit your quiz!</strong>';
            } else {
                message.textContent = `After ${MAX_TAB_WARNINGS} warnings, your quiz will be automatically submitted.`;
            }

            overlay.classList.add('show');
        }

        function dismissTabWarning() {
            document.getElementById('tabWarningOverlay').classList.remove('show');
        }

        function autoSubmitDueToTabSwitch() {
            if (quizSubmitted) return;
            
            quizSubmitted = true;

            Swal.fire({
                title: '🚫 Quiz Auto-Submitted',
                html: `<p>You exceeded the maximum allowed tab switches (${MAX_TAB_WARNINGS}).</p>
                       <p>Your quiz has been automatically submitted.</p>
                       <p style="color: #dc3545; font-weight: 600;">This activity has been logged and will be visible to staff.</p>`,
                icon: 'error',
                allowOutsideClick: false,
                confirmButtonText: 'View Results'
            }).then(() => {
                submitQuizWithFlag(true); // true = flagged for tab switching
            });
        }

        // ==================== QUESTION TYPE HELPERS ====================
        function getQuestionTypeLabel(type) {
            const labels = {
                'multiple_choice': 'Multiple Choice',
                'fill_blank': 'Fill in the Blank',
                'identification': 'Identification',
                'enumeration': 'Enumeration'
            };
            return labels[type] || 'Multiple Choice';
        }

        function getQuestionType(question) {
            return question.question_type || 'multiple_choice';
        }

        // ==================== QUESTION NAVIGATION ====================
        function initializeQuestionNavigation() {
            const navGrid = document.getElementById('questionNavGrid');
            navGrid.innerHTML = '';
            
            questionsData.forEach((q, index) => {
                const btn = document.createElement('button');
                btn.className = 'question-nav-btn';
                btn.textContent = index + 1;
                btn.title = getQuestionTypeLabel(getQuestionType(q));
                btn.onclick = () => jumpToQuestion(index);
                navGrid.appendChild(btn);
            });
            
            updateQuestionNavigation();
        }

        function updateQuestionNavigation() {
            const buttons = document.querySelectorAll('.question-nav-btn');
            
            buttons.forEach((btn, index) => {
                btn.classList.remove('answered', 'current');
                
                if (index === currentQuestionIndex) {
                    btn.classList.add('current');
                } else if (userAnswers[index] !== null) {
                    btn.classList.add('answered');
                }
            });
            
            const answeredCount = userAnswers.filter(a => a !== null).length;
            document.getElementById('answeredCount').textContent = answeredCount;
            document.getElementById('unansweredCount').textContent = questionsData.length - answeredCount;
            document.getElementById('answeredDisplay').textContent = answeredCount;
        }

        function toggleQuestionNav() {
            const container = document.getElementById('questionNavContainer');
            const toggleText = document.getElementById('navToggleText');
            
            container.classList.toggle('open');
            toggleText.textContent = container.classList.contains('open') 
                ? '📋 Hide Questions' 
                : '📋 Show All Questions';
        }

        function jumpToQuestion(index) {
            if (index < 0 || index >= questionsData.length) return;
            
            saveCurrentAnswer();
            currentQuestionIndex = index;
            loadQuestion();
        }

        // ==================== QUESTION RENDERING ====================
        function loadQuestion() {
            if (currentQuestionIndex >= questionsData.length || currentQuestionIndex < 0) return;

            const question = questionsData[currentQuestionIndex];
            const questionContainer = document.getElementById('questionContainer');
            const previousAnswer = userAnswers[currentQuestionIndex];
            const questionType = getQuestionType(question);
            
            let questionHTML = `
                <span class="question-type-badge ${questionType}">
                    ${getQuestionTypeLabel(questionType)}
                </span>
                <h4>${currentQuestionIndex + 1}. ${escapeHtml(question.question_text)}</h4>
            `;
            
            switch (questionType) {
                case 'fill_blank':
                    questionHTML += renderFillBlankQuestion(previousAnswer);
                    break;
                case 'identification':
                    questionHTML += renderIdentificationQuestion(previousAnswer);
                    break;
                case 'enumeration':
                    questionHTML += renderEnumerationQuestion(question, previousAnswer);
                    break;
                case 'multiple_choice':
                default:
                    questionHTML += renderMultipleChoiceQuestion(question, previousAnswer);
                    break;
            }
            
            questionContainer.innerHTML = questionHTML;
            
            // Set text content properly to avoid HTML entity issues
            questionContainer.querySelectorAll('[data-text]').forEach(el => {
                el.textContent = el.getAttribute('data-text');
            });
            
            document.getElementById('currentQuestion').textContent = currentQuestionIndex + 1;
            
            updateNavigationButtons();
            updateProgress();
            updateQuestionNavigation();
        }

        function renderMultipleChoiceQuestion(question, previousAnswer) {
            const optionA = escapeHtml(question.option_a || '');
            const optionB = escapeHtml(question.option_b || '');
            const optionC = escapeHtml(question.option_c || '');
            const optionD = escapeHtml(question.option_d || '');
            
            return `
                <div class="options">
                    <label>
                        <input type="radio" name="answer" value="A" ${previousAnswer === 'A' ? 'checked' : ''}>
                        <span data-text="${optionA.replace(/"/g, '&quot;')}"></span>
                    </label>
                    <label>
                        <input type="radio" name="answer" value="B" ${previousAnswer === 'B' ? 'checked' : ''}>
                        <span data-text="${optionB.replace(/"/g, '&quot;')}"></span>
                    </label>
                    <label>
                        <input type="radio" name="answer" value="C" ${previousAnswer === 'C' ? 'checked' : ''}>
                        <span data-text="${optionC.replace(/"/g, '&quot;')}"></span>
                    </label>
                    <label>
                        <input type="radio" name="answer" value="D" ${previousAnswer === 'D' ? 'checked' : ''}>
                        <span data-text="${optionD.replace(/"/g, '&quot;')}"></span>
                    </label>
                </div>
            `;
        }

        function renderFillBlankQuestion(previousAnswer) {
            return `
                <input type="text" 
                       class="fill-blank-input" 
                       id="textAnswer" 
                       placeholder="Type your answer here..." 
                       value="${previousAnswer ? escapeHtml(previousAnswer) : ''}"
                       autocomplete="off">
            `;
        }

        function renderIdentificationQuestion(previousAnswer) {
            return `
                <input type="text" 
                       class="identification-input" 
                       id="textAnswer" 
                       placeholder="Identify and type your answer here..." 
                       value="${previousAnswer ? escapeHtml(previousAnswer) : ''}"
                       autocomplete="off">
            `;
        }

        function renderEnumerationQuestion(question, previousAnswer) {
            const expectedItems = (question.correct_answer || '').split(',').length;
            const previousItems = previousAnswer ? previousAnswer.split('|||') : [];
            
            let itemsHTML = '';
            for (let i = 0; i < expectedItems; i++) {
                const savedValue = previousItems[i] || '';
                itemsHTML += `
                    <div class="enumeration-item">
                        <span class="item-number">${i + 1}</span>
                        <input type="text" 
                               class="enum-input" 
                               data-index="${i}" 
                               placeholder="Item ${i + 1}..." 
                               value="${escapeHtml(savedValue)}"
                               autocomplete="off">
                    </div>
                `;
            }
            
            return `
                <div class="enumeration-container">
                    <div class="enumeration-label">List ${expectedItems} item(s):</div>
                    <div class="enumeration-items">
                        ${itemsHTML}
                    </div>
                </div>
            `;
        }

        function escapeHtml(text) {
            if (!text) return '';
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#x27;');
        }

        function updateNavigationButtons() {
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const skipBtn = document.getElementById('skipBtn');
            const submitBtn = document.getElementById('submitBtn');

            prevBtn.style.display = currentQuestionIndex === 0 ? 'none' : 'inline-block';

            if (currentQuestionIndex === questionsData.length - 1) {
                nextBtn.style.display = 'none';
                skipBtn.style.display = 'none';
                submitBtn.style.display = 'inline-block';
            } else {
                nextBtn.style.display = 'inline-block';
                skipBtn.style.display = 'inline-block';
                submitBtn.style.display = 'none';
            }
        }

        function updateProgress() {
            const answeredCount = userAnswers.filter(a => a !== null).length;
            const progress = (answeredCount / questionsData.length) * 100;
            document.getElementById('progressFill').style.width = progress + '%';
        }

        // ==================== NAVIGATION ACTIONS ====================
        function saveCurrentAnswer() {
            const question = questionsData[currentQuestionIndex];
            const questionType = getQuestionType(question);
            
            let answer = null;
            
            switch (questionType) {
                case 'fill_blank':
                case 'identification':
                    const textInput = document.getElementById('textAnswer');
                    if (textInput && textInput.value.trim()) {
                        answer = textInput.value.trim();
                    }
                    break;
                    
                case 'enumeration':
                    const enumInputs = document.querySelectorAll('.enum-input');
                    const enumAnswers = [];
                    let hasAnswer = false;
                    enumInputs.forEach(input => {
                        const val = input.value.trim();
                        enumAnswers.push(val);
                        if (val) hasAnswer = true;
                    });
                    if (hasAnswer) {
                        answer = enumAnswers.join('|||');
                    }
                    break;
                    
                case 'multiple_choice':
                default:
                    const selectedAnswer = document.querySelector('input[name="answer"]:checked');
                    if (selectedAnswer) {
                        answer = selectedAnswer.value;
                    }
                    break;
            }
            
            userAnswers[currentQuestionIndex] = answer;
            updateQuestionNavigation();
        }

        function hasCurrentAnswer() {
            const question = questionsData[currentQuestionIndex];
            const questionType = getQuestionType(question);
            
            switch (questionType) {
                case 'fill_blank':
                case 'identification':
                    const textInput = document.getElementById('textAnswer');
                    return textInput && textInput.value.trim().length > 0;
                    
                case 'enumeration':
                    const enumInputs = document.querySelectorAll('.enum-input');
                    let hasAnswer = false;
                    enumInputs.forEach(input => {
                        if (input.value.trim()) hasAnswer = true;
                    });
                    return hasAnswer;
                    
                case 'multiple_choice':
                default:
                    return document.querySelector('input[name="answer"]:checked') !== null;
            }
        }

        function previousQuestion() {
            saveCurrentAnswer();
            currentQuestionIndex--;
            if (currentQuestionIndex < 0) currentQuestionIndex = 0;
            loadQuestion();
        }

        function nextQuestion() {
            if (!hasCurrentAnswer()) {
                Swal.fire({
                    title: 'No Answer Provided',
                    text: 'Please provide an answer or click "Skip" to continue.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            saveCurrentAnswer();
            currentQuestionIndex++;
            
            if (currentQuestionIndex < questionsData.length) {
                loadQuestion();
            }
        }

        function skipQuestion() {
            currentQuestionIndex++;
            if (currentQuestionIndex < questionsData.length) {
                loadQuestion();
            } else {
                const firstUnanswered = userAnswers.findIndex(a => a === null);
                if (firstUnanswered !== -1) {
                    currentQuestionIndex = firstUnanswered;
                    loadQuestion();
                } else {
                    currentQuestionIndex = questionsData.length - 1;
                    loadQuestion();
                }
            }
        }

        // ==================== ANSWER CHECKING ====================
        function checkAnswer(question, userAnswer) {
            if (!userAnswer || userAnswer === 'N/A') return false;
            
            const questionType = getQuestionType(question);
            const correctAnswer = question.correct_answer || '';
            
            switch (questionType) {
                case 'fill_blank':
                case 'identification':
                    return userAnswer.toLowerCase().trim() === correctAnswer.toLowerCase().trim();
                    
                case 'enumeration':
                    const userItems = userAnswer.split('|||')
                        .map(item => item.toLowerCase().trim())
                        .filter(item => item.length > 0);
                    const correctItems = correctAnswer.split(',')
                        .map(item => item.toLowerCase().trim())
                        .filter(item => item.length > 0);
                    
                    if (userItems.length !== correctItems.length) return false;
                    
                    const userSet = new Set(userItems);
                    return correctItems.every(item => userSet.has(item));
                    
                case 'multiple_choice':
                default:
                    return userAnswer === correctAnswer;
            }
        }

        // ==================== QUIZ SUBMISSION ====================
        function submitQuiz() {
            submitQuizWithFlag(false, false);
        }

        function submitQuizWithFlag(flaggedForTabSwitch, timerExpired = false) {
            saveCurrentAnswer();
            
            // Stop the timer
            if (timerInterval) {
                clearInterval(timerInterval);
            }
            
            const unanswered = userAnswers.filter(a => a === null).length;
            
            if (unanswered > 0 && !flaggedForTabSwitch && !timerExpired) {
                Swal.fire({
                    title: '⚠️ Unanswered Questions',
                    html: `<p>You have <strong>${unanswered}</strong> unanswered question(s).</p>
                           <p>Unanswered questions will be marked as incorrect.</p>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Submit Anyway',
                    cancelButtonText: 'Go Back'
                }).then((result) => {
                    if (result.isConfirmed) {
                        performSubmission(flaggedForTabSwitch, timerExpired);
                    } else {
                        // Restart timer if they go back
                        if (QUIZ_TIME_LIMIT > 0 && remainingTime > 0) {
                            initQuizTimer();
                        }
                        const firstUnanswered = userAnswers.findIndex(a => a === null);
                        if (firstUnanswered !== -1) {
                            jumpToQuestion(firstUnanswered);
                        }
                    }
                });
            } else {
                performSubmission(flaggedForTabSwitch, timerExpired);
            }
        }

        function performSubmission(flaggedForTabSwitch, timerExpired = false) {
            if (quizSubmitted && !flaggedForTabSwitch && !timerExpired) return;
            quizSubmitted = true;

            // Calculate score using the new checkAnswer function
            score = 0;
            const responses = [];
            
            questionsData.forEach((question, index) => {
                const userAnswer = userAnswers[index];
                const isCorrect = checkAnswer(question, userAnswer);
                
                if (isCorrect) score++;
                
                responses.push({
                    question_id: question.question_id,
                    question_type: getQuestionType(question),
                    user_answer: userAnswer || 'N/A',
                    is_correct: isCorrect
                });
            });

            const percentage = Math.round((score / questionsData.length) * 100);
            const timeTaken = Math.floor((Date.now() - quizStartTime) / 1000);

            const quizResult = {
                user_id: PHP_USER_ID,
                book_id: PHP_BOOK_ID,
                total_questions: questionsData.length,
                correct_answers: score,
                score_percentage: percentage,
                time_taken: timeTaken,
                responses: responses,
                tab_switch_count: tabSwitchCount,
                tab_switch_flagged: flaggedForTabSwitch || tabSwitchCount > 0,
                tab_switch_log: tabSwitchLog,
                time_expired: timerExpired
            };

            console.log('[Quiz] Submitting quiz result:', quizResult);

            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.textContent = '⏳ Submitting...';

            fetch('save-quiz-result.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(quizResult)
            })
            .then(response => response.text())
            .then(text => {
                console.log('[Quiz] Raw response:', text);
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid JSON response');
                }
            })
            .then(data => {
                if (data.success) {
                    showResults(data, percentage, flaggedForTabSwitch);
                } else {
                    throw new Error(data.message || 'Failed to save quiz');
                }
            })
            .catch(error => {
                console.error('[Quiz] Error:', error);
                submitBtn.disabled = false;
                submitBtn.textContent = '✅ Submit Quiz';
                quizSubmitted = false;
                
                Swal.fire({
                    title: 'Error',
                    text: 'Failed to save quiz result. Please try again.',
                    icon: 'error'
                });
            });
        }

        function showResults(data, percentage, wasFlagged) {
            document.getElementById('finalScore').textContent = score + '/' + questionsData.length;
            document.getElementById('percentage').textContent = percentage + '%';

            document.getElementById('booksCompletedStat').textContent = data.data.books_completed;
            document.getElementById('averageScoreStat').textContent = data.data.average_score + '%';
            document.getElementById('rewardsEarnedStat').textContent = data.data.rewards_earned;

            let message = '';
            if (wasFlagged) {
                message = `<p style="color: #dc3545; font-weight: 600;">⚠️ This attempt was flagged for tab switching (${tabSwitchCount} switches detected).</p>`;
            }
            
            if (percentage >= 90) {
                message += '<p>🌟 Excellent work! Outstanding performance!</p>';
            } else if (percentage >= 70) {
                message += '<p>✅ Great job! You passed the quiz!</p>';
            } else if (percentage >= 50) {
                message += '<p>📚 Good effort! Keep reading to improve.</p>';
            } else {
                message += '<p>💪 Keep practicing! You can do better next time.</p>';
            }
            
            document.getElementById('resultMessage').innerHTML = message;

            if (data.data.new_rewards && data.data.new_rewards.length > 0) {
                const rewardsSection = document.getElementById('rewardsEarnedSection');
                const rewardsContainer = document.getElementById('newRewardsContainer');
                
                rewardsContainer.innerHTML = '';
                data.data.new_rewards.forEach(reward => {
                    const rewardDiv = document.createElement('div');
                    rewardDiv.className = 'reward-item';
                    rewardDiv.textContent = '🎁 ' + reward;
                    rewardsContainer.appendChild(rewardDiv);
                });
                
                rewardsSection.style.display = 'block';
            }

            document.getElementById('quizContent').style.display = 'none';
            document.getElementById('quizResults').style.display = 'block';
            document.getElementById('progressFill').style.width = '100%';

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            const scanAnotherBtn = document.getElementById('scanAnotherBtn');
            if (scanAnotherBtn) {
                scanAnotherBtn.addEventListener('click', function() {
                    this.disabled = true;
                    this.textContent = '⏳ Redirecting...';
                    
                    fetch('clear-scanned-book.php', { 
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('[Quiz] Clear response:', data);
                        window.location.href = 'index.php';
                    })
                    .catch(err => {
                        console.error('[Quiz] Clear error:', err);
                        window.location.href = 'index.php';
                    });
                });
            }
            
            initializeQuestionNavigation();
            loadQuestion();
            initTabSwitchDetection();
            initQuizTimer(); // Start quiz timer if time limit is set
        });

        // Prevent accidental page leave during quiz
        window.addEventListener('beforeunload', function(e) {
            if (!quizSubmitted && userAnswers.some(a => a !== null)) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    </script>
</body>
</html>
