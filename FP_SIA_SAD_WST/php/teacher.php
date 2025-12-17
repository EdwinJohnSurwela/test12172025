<?php
require_once 'config.php';

// Check if user is logged in as teacher
check_user_type(['teacher']);

// Fetch statistics
$stats = [];

// Total students in system (teachers can see all)
$sql = "SELECT COUNT(*) as total FROM users WHERE user_type = 'student' AND status = 'active'";
$result = $conn->query($sql);
$stats['total_students'] = $result->fetch_assoc()['total'];

// Average quiz score
$sql = "SELECT AVG(score_percentage) as avg_score FROM quiz_attempts";
$result = $conn->query($sql);
$stats['average_score'] = round($result->fetch_assoc()['avg_score'], 1);

// Total books read
$sql = "SELECT COUNT(DISTINCT CONCAT(user_id, '-', book_id)) as total FROM quiz_attempts WHERE score_percentage >= 70";
$result = $conn->query($sql);
$stats['total_books_read'] = $result->fetch_assoc()['total'];

// Average books per student
$stats['avg_books_per_student'] = $stats['total_students'] > 0 ? 
    round($stats['total_books_read'] / $stats['total_students'], 1) : 0;

// Pen rewards
$sql = "SELECT COUNT(*) as total FROM user_rewards WHERE reward_id = 1";
$result = $conn->query($sql);
$stats['pens_given'] = $result->fetch_assoc()['total'];

// Notebook rewards
$sql = "SELECT COUNT(*) as total FROM user_rewards WHERE reward_id = 2";
$result = $conn->query($sql);
$stats['notebooks_given'] = $result->fetch_assoc()['total'];

// ===== Chart Data Queries for Teacher Dashboard =====

// Data for Pie Chart: Score Distribution
$sql = "SELECT 
            CASE 
                WHEN score_percentage >= 90 THEN 'Excellent (90-100%)'
                WHEN score_percentage >= 70 THEN 'Good (70-89%)'
                WHEN score_percentage >= 50 THEN 'Average (50-69%)'
                ELSE 'Needs Improvement (<50%)'
            END as score_range,
            COUNT(*) as count
        FROM quiz_attempts
        GROUP BY score_range
        ORDER BY 
            CASE score_range
                WHEN 'Excellent (90-100%)' THEN 1
                WHEN 'Good (70-89%)' THEN 2
                WHEN 'Average (50-69%)' THEN 3
                ELSE 4
            END";
$chart_scores_result = $conn->query($sql);
$chart_scores_data = [];
while ($row = $chart_scores_result->fetch_assoc()) {
    $chart_scores_data[] = $row;
}

// Monthly Quiz Activity (Last 6 months)
$sql = "SELECT 
            DATE_FORMAT(attempt_date, '%Y-%m') as month,
            DATE_FORMAT(attempt_date, '%b %Y') as month_label,
            COUNT(*) as attempts,
            ROUND(AVG(score_percentage), 1) as avg_score,
            COUNT(DISTINCT user_id) as unique_students
        FROM quiz_attempts
        WHERE attempt_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY month, month_label
        ORDER BY month ASC";
$chart_monthly_result = $conn->query($sql);
$chart_monthly_data = [];
while ($row = $chart_monthly_result->fetch_assoc()) {
    $chart_monthly_data[] = $row;
}

// Data for Grade Level Performance Chart (Grades 1-6 only)
$sql = "SELECT 
            grade_level,
            COALESCE(total_books, 0) as total_books,
            COALESCE(total_attempts, 0) as total_attempts,
            COALESCE(books_with_attempts, 0) as books_with_attempts,
            COALESCE(avg_score, 0) as avg_score,
            COALESCE(passing_attempts, 0) as passing_attempts,
            COALESCE(pass_rate, 0) as pass_rate,
            COALESCE(unique_students, 0) as unique_students
        FROM (
            SELECT '1' as grade_level UNION SELECT '2' UNION SELECT '3' UNION SELECT '4' UNION SELECT '5' UNION SELECT '6'
        ) grades
        LEFT JOIN (
            SELECT 
                b.recommended_grade_level as gl,
                COUNT(DISTINCT b.book_id) as total_books,
                COUNT(qa.attempt_id) as total_attempts,
                COUNT(DISTINCT CASE WHEN qa.attempt_id IS NOT NULL THEN b.book_id END) as books_with_attempts,
                ROUND(AVG(qa.score_percentage), 1) as avg_score,
                SUM(CASE WHEN qa.score_percentage >= 70 THEN 1 ELSE 0 END) as passing_attempts,
                ROUND((SUM(CASE WHEN qa.score_percentage >= 70 THEN 1 ELSE 0 END) / NULLIF(COUNT(qa.attempt_id), 0)) * 100, 1) as pass_rate,
                COUNT(DISTINCT qa.user_id) as unique_students
            FROM books b
            LEFT JOIN quiz_attempts qa ON b.book_id = qa.book_id
            WHERE b.recommended_grade_level IN ('1', '2', '3', '4', '5', '6')
            GROUP BY b.recommended_grade_level
        ) data ON grades.grade_level = data.gl
        ORDER BY CAST(grade_level AS UNSIGNED) ASC";
$chart_grade_result = $conn->query($sql);
$chart_grade_data = [];
while ($row = $chart_grade_result->fetch_assoc()) {
    $chart_grade_data[] = $row;
}

// Top 10 Performing Students
$sql = "SELECT 
            u.full_name,
            u.student_id,
            COUNT(qa.attempt_id) as total_attempts,
            COUNT(DISTINCT qa.book_id) as books_read,
            ROUND(AVG(qa.score_percentage), 1) as avg_score,
            SUM(CASE WHEN qa.score_percentage >= 70 THEN 1 ELSE 0 END) as passing_count
        FROM users u
        INNER JOIN quiz_attempts qa ON u.user_id = qa.user_id
        WHERE u.user_type = 'student'
        GROUP BY u.user_id, u.full_name, u.student_id
        HAVING total_attempts >= 2
        ORDER BY avg_score DESC, books_read DESC
        LIMIT 10";
$chart_top_students_result = $conn->query($sql);
$chart_top_students_data = [];
while ($row = $chart_top_students_result->fetch_assoc()) {
    $chart_top_students_data[] = $row;
}

// ===== END Chart Data Queries =====

// Student progress with search, filter, and sort
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$book_filter = isset($_GET['book_filter']) ? (int)$_GET['book_filter'] : 0;
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'date_desc';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = ["u.user_type = 'student'"];
$params = [];
$types = '';

if ($search !== '') {
    $where_conditions[] = "(u.full_name LIKE ? OR u.student_id LIKE ? OR b.title LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

if ($book_filter > 0) {
    $where_conditions[] = "qa.book_id = ?";
    $params[] = $book_filter;
    $types .= 'i';
}

$where_clause = implode(' AND ', $where_conditions);

// Determine ORDER BY
$order_by = match($sort_by) {
    'score_high' => 'qa.score_percentage DESC',
    'score_low' => 'qa.score_percentage ASC',
    'date_asc' => 'qa.attempt_date ASC',
    default => 'qa.attempt_date DESC', // date_desc
};

// Count total records
$count_sql = "SELECT COUNT(*) as total 
              FROM quiz_attempts qa
              INNER JOIN users u ON qa.user_id = u.user_id
              INNER JOIN books b ON qa.book_id = b.book_id
              WHERE $where_clause";

if (!empty($params)) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_records = $conn->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $per_page);

// Fetch paginated results
$sql = "SELECT 
            u.full_name,
            u.student_id,
            b.title as book_title,
            qa.correct_answers,
            qa.total_questions,
            qa.score_percentage,
            qa.status,
            qa.tab_switch_count,
            DATE_FORMAT(qa.attempt_date, '%b %d, %Y %h:%i %p') as formatted_date
        FROM quiz_attempts qa
        INNER JOIN users u ON qa.user_id = u.user_id
        INNER JOIN books b ON qa.book_id = b.book_id
        WHERE $where_clause
        ORDER BY $order_by
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$student_progress = $stmt->get_result();
$stmt->close();

// Handle quiz timer update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_quiz_timer'])) {
    header('Content-Type: application/json');
    $book_id = (int)$_POST['book_id'];
    $hours = (int)($_POST['hours'] ?? 0);
    $minutes = (int)($_POST['minutes'] ?? 0);
    $seconds = (int)($_POST['seconds'] ?? 0);
    
    // Calculate total seconds (0 means no limit)
    $total_seconds = ($hours * 3600) + ($minutes * 60) + $seconds;
    $quiz_time_limit = $total_seconds > 0 ? $total_seconds : null;
    
    $sql = "UPDATE books SET quiz_time_limit = ? WHERE book_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $quiz_time_limit, $book_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Quiz timer updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update quiz timer.']);
    }
    $stmt->close();
    exit;
}

// Handle max attempts update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_quiz_attempts'])) {
    header('Content-Type: application/json');
    $book_id = (int)$_POST['book_id'];
    $max_attempts_raw = $_POST['max_attempts'] ?? '';
    $max_attempts = is_numeric($max_attempts_raw) ? (int)$max_attempts_raw : 0;

    // Treat 0 or negative as unlimited (NULL in DB)
    $db_value = ($max_attempts > 0) ? $max_attempts : null;

    if ($db_value === null) {
        $sql = "UPDATE books SET max_quiz_attempts = NULL WHERE book_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $book_id);
    } else {
        $sql = "UPDATE books SET max_quiz_attempts = ? WHERE book_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $db_value, $book_id);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Max attempts updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update max attempts.']);
    }
    $stmt->close();
    exit;
}

// Handle quiz question update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_questions'])) {
    $book_id = (int)$_POST['book_id'];
    $questions = $_POST['questions'] ?? [];
    
    $updated_count = 0;
    
    foreach ($questions as $q_id => $q_data) {
        $question_text = trim($q_data['text']);
        $question_type = $q_data['type'] ?? 'multiple_choice';
        
        // Handle different answer formats based on type
        if ($question_type === 'multiple_choice') {
            $option_a = trim($q_data['option_a'] ?? '');
            $option_b = trim($q_data['option_b'] ?? '');
            $option_c = trim($q_data['option_c'] ?? '');
            $option_d = trim($q_data['option_d'] ?? '');
            $correct_answer = $q_data['correct'] ?? '';
        } elseif ($question_type === 'enumeration') {
            $option_a = '';
            $option_b = '';
            $option_c = '';
            $option_d = '';
            $enum_answers = $q_data['enum'] ?? [];
            $correct_answer = implode(',', array_map('trim', $enum_answers));
        } else {
            // identification or fill_blank
            $option_a = '';
            $option_b = '';
            $option_c = '';
            $option_d = '';
            $correct_answer = trim($q_data['answer'] ?? '');
        }
        
        $update_sql = "UPDATE quiz_questions 
                      SET question_text = ?, question_type = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_answer = ?
                      WHERE question_id = ? AND book_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("sssssssii", $question_text, $question_type, $option_a, $option_b, $option_c, $option_d, $correct_answer, $q_id, $book_id);
        
        if ($update_stmt->execute()) {
            $updated_count++;
        }
        $update_stmt->close();
    }
    
    if ($updated_count > 0) {
        // Log the action
        $log_sql = "INSERT INTO system_logs (user_id, action, description) VALUES (?, 'quiz_updated', ?)";
        $log_stmt = $conn->prepare($log_sql);
        $log_desc = "Updated quiz questions for book ID: $book_id";
        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'Questions updated successfully!']);
        exit();
    }
}

// Handle adding new question
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_question'])) {
    $book_id = (int)$_POST['book_id'];
    $question_text = trim($_POST['question_text']);
    $question_type = $_POST['question_type'] ?? 'multiple_choice';
    $option_a = trim($_POST['option_a'] ?? '');
    $option_b = trim($_POST['option_b'] ?? '');
    $option_c = trim($_POST['option_c'] ?? '');
    $option_d = trim($_POST['option_d'] ?? '');
    $correct_answer = trim($_POST['correct_answer'] ?? '');
    $difficulty = $_POST['difficulty'] ?? 'medium';
    
    $insert_sql = "INSERT INTO quiz_questions (book_id, question_text, question_type, option_a, option_b, option_c, option_d, correct_answer, difficulty_level, created_by) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_sql);
    $user_id = $_SESSION['user_id'];
    $insert_stmt->bind_param("issssssssi", $book_id, $question_text, $question_type, $option_a, $option_b, $option_c, $option_d, $correct_answer, $difficulty, $user_id);
    
    if ($insert_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Question added successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add question.']);
    }
    $insert_stmt->close();
    exit();
}

// Handle file upload for questions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_questions'])) {
    $book_id = (int)$_POST['book_id'];
    
    if (isset($_FILES['questions_file']) && $_FILES['questions_file']['error'] == 0) {
        $file = $_FILES['questions_file'];
        
        // Security Check 1: File size limit (500KB max)
        $max_file_size = 500 * 1024;
        if ($file['size'] > $max_file_size) {
            echo json_encode(['success' => false, 'message' => "❌ File too large. Maximum size is 500KB."]);
            exit();
        }
        
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Security Check 2: File extension validation
        if ($file_ext !== 'txt') {
            echo json_encode(['success' => false, 'message' => "❌ Only .txt files are allowed."]);
            exit();
        }
        
        // Security Check 3: MIME type validation
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);
        
        $allowed_mimes = ['text/plain', 'application/octet-stream'];
        if (!in_array($mime_type, $allowed_mimes)) {
            echo json_encode(['success' => false, 'message' => "❌ Invalid file type. Only plain text files are allowed."]);
            exit();
        }
        
        // Security Check 4: File name sanitization
        $original_filename = basename($file['name']);
        $safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $original_filename);
        
        // Security Check 5: Read and validate file content
        $content = file_get_contents($file['tmp_name']);
        
        // Check for null bytes (file upload attack)
        if (strpos($content, "\0") !== false) {
            echo json_encode(['success' => false, 'message' => "❌ Invalid file content detected."]);
            exit();
        }
        
        // Security Check 6: Validate UTF-8 encoding
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'auto');
        }
        
        // Security Check 7: Remove any PHP/HTML/JavaScript code
        $content = strip_tags($content);
        
        $lines = explode("\n", $content);
        $questions_added = 0;
        $i = 0;
        
        while ($i < count($lines)) {
            $line = trim($lines[$i]);
            
            if (empty($line)) {
                $i++;
                continue;
            }
            
            if (strpos($line, 'Q:') === 0 || strpos($line, 'Question:') === 0) {
                $question_text = sanitize_input(trim(substr($line, strpos($line, ':') + 1)));
                $option_a = '';
                $option_b = '';
                $option_c = '';
                $option_d = '';
                $correct_answer = '';
                
                for ($j = 1; $j <= 5; $j++) {
                    $i++;
                    if ($i >= count($lines)) break;
                    
                    $option_line = trim($lines[$i]);
                    
                    if (strpos($option_line, 'A)') === 0 || strpos($option_line, 'A.') === 0) {
                        $option_a = sanitize_input(trim(substr($option_line, 2)));
                    } elseif (strpos($option_line, 'B)') === 0 || strpos($option_line, 'B.') === 0) {
                        $option_b = sanitize_input(trim(substr($option_line, 2)));
                    } elseif (strpos($option_line, 'C)') === 0 || strpos($option_line, 'C.') === 0) {
                        $option_c = sanitize_input(trim(substr($option_line, 2)));
                    } elseif (strpos($option_line, 'D)') === 0 || strpos($option_line, 'D.') === 0) {
                        $option_d = sanitize_input(trim(substr($option_line, 2)));
                    } elseif (strpos($option_line, 'ANSWER:') === 0 || strpos($option_line, 'Answer:') === 0) {
                        $answer_raw = trim(substr($option_line, strpos($option_line, ':') + 1));
                        if (in_array(strtoupper($answer_raw), ['A', 'B', 'C', 'D'])) {
                            $correct_answer = strtoupper($answer_raw);
                        }
                    }
                }
                
                // Validate all fields before insertion
                if (!empty($question_text) && !empty($option_a) && !empty($option_b) && 
                    !empty($option_c) && !empty($option_d) && !empty($correct_answer)) {
                    
                    if (strlen($question_text) > 500 || strlen($option_a) > 255 || 
                        strlen($option_b) > 255 || strlen($option_c) > 255 || strlen($option_d) > 255) {
                        continue;
                    }
                    
                    $insert_sql = "INSERT INTO quiz_questions (book_id, question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty_level, created_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'medium', ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $user_id = $_SESSION['user_id'];
                    $insert_stmt->bind_param("issssssi", $book_id, $question_text, $option_a, $option_b, $option_c, $option_d, $correct_answer, $user_id);
                    
                    if ($insert_stmt->execute()) {
                        $questions_added++;
                    }
                    $insert_stmt->close();
                }
            }
            
            $i++;
        }
        
        if ($questions_added > 0) {
            $message = "✅ Successfully uploaded $questions_added question(s) from file!";
            
            $log_sql = "INSERT INTO system_logs (user_id, action, description, ip_address) 
                       VALUES (?, 'questions_uploaded', ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_desc = "Uploaded $questions_added questions for book ID: $book_id from file: $safe_filename";
            $ip = $_SERVER['REMOTE_ADDR'];
            $log_stmt->bind_param("iss", $_SESSION['user_id'], $log_desc, $ip);
            $log_stmt->execute();
            $log_stmt->close();
            
            echo json_encode(['success' => true, 'message' => $message]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => "❌ No valid questions found in the file. Please check the format."]);
            exit();
        }
        
        // Security: Delete the uploaded temporary file
        if (file_exists($file['tmp_name'])) {
            @unlink($file['tmp_name']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "❌ Please select a file to upload."]);
        exit();
    }
}

// Handle quiz question deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_question'])) {
    $question_id = (int)$_POST['question_id'];
    
    $delete_sql = "DELETE FROM quiz_questions WHERE question_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $question_id);
    
    if ($delete_stmt->execute()) {
        // Log the action
        $log_sql = "INSERT INTO system_logs (user_id, action, description) VALUES (?, 'question_deleted', ?)";
        $log_stmt = $conn->prepare($log_sql);
        $log_desc = "Deleted quiz question ID: $question_id";
        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode(['success' => true, 'message' => 'Question deleted successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete question.']);
    }
    $delete_stmt->close();
    exit();
}

// Handle entire quiz deletion (all questions for a book)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_quiz'])) {
    $book_id = (int)$_POST['book_id'];
    
    // Count how many questions will be deleted
    $count_sql = "SELECT COUNT(*) as total FROM quiz_questions WHERE book_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("i", $book_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_data = $count_result->fetch_assoc();
    $questions_count = $count_data['total'];
    $count_stmt->close();
    
    if ($questions_count == 0) {
        echo json_encode(['success' => false, 'message' => 'No questions found for this book.']);
        exit();
    }
    
    // Delete all questions for this book
    $delete_sql = "DELETE FROM quiz_questions WHERE book_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $book_id);
    
    if ($delete_stmt->execute()) {
        // Log the action
        $log_sql = "INSERT INTO system_logs (user_id, action, description) VALUES (?, 'quiz_deleted', ?)";
        $log_stmt = $conn->prepare($log_sql);
        $log_desc = "Deleted entire quiz ($questions_count questions) for book ID: $book_id";
        $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode(['success' => true, 'message' => "Successfully deleted $questions_count question(s)!"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete quiz.']);
    }
    $delete_stmt->close();
    exit();
}

// Quiz Management Pagination
$quiz_page = isset($_GET['quiz_page']) ? max(1, (int)$_GET['quiz_page']) : 1;
$quiz_per_page = 10;
$quiz_offset = ($quiz_page - 1) * $quiz_per_page;

// Count total books
$count_books_sql = "SELECT COUNT(*) as total FROM books";
$total_books = $conn->query($count_books_sql)->fetch_assoc()['total'];
$total_quiz_pages = ceil($total_books / $quiz_per_page);

// Get paginated books for quiz management (include quiz_time_limit)
$books_sql = "SELECT book_id, title, author, qr_code, 
              COALESCE(quiz_time_limit, 0) as quiz_time_limit 
              FROM books ORDER BY title ASC LIMIT ? OFFSET ?";
$books_stmt = $conn->prepare($books_sql);
$books_stmt->bind_param("ii", $quiz_per_page, $quiz_offset);
$books_stmt->execute();
$books_result = $books_stmt->get_result();
$all_books = [];
while ($row = $books_result->fetch_assoc()) {
    $all_books[] = $row;
}
$books_stmt->close();

// Get all books for filters (Student Progress)
$all_books_filter_sql = "SELECT book_id, title FROM books ORDER BY title ASC";
$all_books_filter = [];
$filter_result = $conn->query($all_books_filter_sql);
while ($row = $filter_result->fetch_assoc()) {
    $all_books_filter[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - Library Hub Tambo</title>
    <link rel="icon" href="../images/library_hub_logo.png" type="image/png">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js (local for offline use) -->
    <script src="vendor/chart.umd.min.js"></script>
    <!-- DataTables CSS -->
    <link href="vendor/datatables.min.css" rel="stylesheet">
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
            cursor: pointer;
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
            text-decoration: none;
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
            font-size: 100px;
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

        .loading-dots::after {
            content: '';
            animation: dots 1.5s steps(4, end) infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        @keyframes dots {
            0%, 20% { content: ''; }
            40% { content: '.'; }
            60% { content: '..'; }
            80%, 100% { content: '...'; }
        }

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
        }

        .header {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.1em;
            color: #666;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 30px;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .dashboard-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }

        .dashboard-card h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.1em;
        }

        .stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #667eea;
        }

        .stats p {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }

        /* Charts Section */
        .charts-section {
            margin: 30px 0;
        }

        .charts-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .charts-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            font-size: 1.4em;
            margin: 0;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: 1px solid #e9ecef;
        }

        .chart-card h4 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.1em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .chart-container.pie-chart {
            height: 280px;
        }

        .chart-card-wide {
            grid-column: 1 / -1;
        }

        .chart-card-wide .chart-container {
            height: 350px;
        }

        /* Top Students List */
        .top-students-list {
            max-height: 320px;
            overflow-y: auto;
        }

        .student-rank-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }

        .student-rank-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .rank-badge {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-right: 15px;
            font-size: 14px;
        }

        .rank-1 { background: linear-gradient(135deg, #ffd700, #ffaa00); color: #333; }
        .rank-2 { background: linear-gradient(135deg, #c0c0c0, #a0a0a0); color: #333; }
        .rank-3 { background: linear-gradient(135deg, #cd7f32, #b87333); color: white; }
        .rank-default { background: #667eea; color: white; }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .student-id-text {
            color: #666;
            font-size: 12px;
        }

        .student-stats {
            text-align: right;
        }

        .student-score {
            font-weight: 700;
            font-size: 18px;
            color: #28a745;
        }

        .student-books {
            color: #666;
            font-size: 12px;
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
        }

        .search-filter-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }

        .search-filter-grid {
            display: grid;
            grid-template-columns: 2fr 1.5fr 1.5fr 1fr;
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            color: white;
            font-weight: 600;
            font-size: 0.9em;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .filter-input,
        .filter-select {
            padding: 12px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            font-size: 14px;
            background: rgba(255,255,255,0.95);
            transition: all 0.3s;
            width: 100%;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: white;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.3);
        }

        .btn-filter {
            padding: 12px 25px;
            background: white;
            color: #667eea;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.3);
            background: #f8f9fa;
        }

        .table-wrapper {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .table thead th {
            color: white;
            padding: 16px 12px;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85em;
            letter-spacing: 0.5px;
        }

        .table tbody td {
            padding: 14px 12px;
            border-bottom: 1px solid #e9ecef;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-flagged {
            background: #fff3cd;
            color: #856404;
        }

        .score-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.95em;
        }

        .score-high {
            background: #d4edda;
            color: #155724;
        }

        .score-medium {
            background: #fff3cd;
            color: #856404;
        }

        .score-low {
            background: #f8d7da;
            color: #721c24;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .pagination-btn {
            padding: 10px 16px;
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .pagination-btn:hover:not(.disabled) {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            color: #666;
            font-weight: 600;
            padding: 0 15px;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: transform 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-small {
            padding: 8px 15px;
            font-size: 14px;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 50px rgba(0,0,0,0.3);
            max-height: 90vh;
            overflow-y: auto;
            width: 90%;
            max-width: 800px;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .close-modal {
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
            background: none;
            border: none;
        }

        .close-modal:hover {
            color: #333;
        }

        .question-block {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .question-block label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .question-block input[type="text"],
        .question-block textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: inherit;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .question-block textarea {
            min-height: 60px;
            resize: vertical;
        }

        .options-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }

        .option-input {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .option-input input[type="radio"] {
            cursor: pointer;
        }

        .option-input input[type="text"] {
            flex: 1;
        }

        .enumeration-answers {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 10px;
        }

        .enum-answer-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .enum-answer-row input {
            flex: 1;
        }

        .btn-remove-enum {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-add-enum {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 8px;
        }

        .file-upload-area {
            border: 3px dashed #667eea;
            border-radius: 10px;
            padding: 40px 20px;
            text-align: center;
            background: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
            margin-top: 15px;
        }

        .file-upload-area:hover {
            border-color: #764ba2;
            background: #e9ecef;
        }

        .file-upload-icon {
            font-size: 48px;
            color: #667eea;
            margin-bottom: 15px;
        }

        .file-upload-text {
            color: #666;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .file-upload-hint {
            color: #999;
            font-size: 14px;
        }

        .file-input-hidden {
            display: none;
        }

        .file-name-display {
            margin-top: 15px;
            padding: 10px;
            background: #e9ecef;
            border-radius: 5px;
            color: #333;
            font-weight: 600;
            display: none;
        }

        .file-name-display.active {
            display: block;
        }

        .remove-file-btn {
            margin-left: 10px;
            color: #dc3545;
            cursor: pointer;
            font-weight: bold;
        }

        @media (max-width: 968px) {
            .search-filter-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard {
                grid-template-columns: 1fr;
            }
            
            .options-grid {
                grid-template-columns: 1fr;
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
    </style>
</head>
<body>
    <div class="scroll-progress-bar" id="scrollProgressBar"></div>

    <div id="bootLoader">
        <div class="boot-logo">
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="boot-icon"><img src="../images/library_hub_logo.png" alt="Library Hub" class="boot-logo"></div>
        </div>
        <div class="boot-text">Teacher Dashboard</div>
        <div class="boot-subtitle">Loading<span class="loading-dots"></span></div>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <a href="index.php" class="sidebar-brand">
            <div class="sidebar-brand-icon"><img src="../images/library_hub_logo.png" alt="Library Hub" class="sidebar-logo"></div>
            <span class="sidebar-brand-text">Library Hub</span>
        </a>

        <ul class="sidebar-menu">
            <li><a class="active" onclick="scrollToSection('dashboard')"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
            <li><a onclick="scrollToSection('analytics')"><i class="fas fa-chart-bar"></i> <span>Analytics</span></a></li>
            <li><a onclick="scrollToSection('quiz-management')"><i class="fas fa-question-circle"></i> <span>Quiz Management</span></a></li>
            <li><a onclick="scrollToSection('student-progress')"><i class="fas fa-user-graduate"></i> <span>Student Progress</span></a></li>
        </ul>

        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </aside>

    <div id="mainContent">
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1>👩‍🏫 Teacher Dashboard</h1>
                <div class="header-actions">
                    <div class="user-profile">
                        <div class="user-avatar">👩‍🏫</div>
                        <div class="user-info-header">
                            <h4><?php echo htmlspecialchars($_SESSION['full_name']); ?></h4>
                            <span>Teacher</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Section -->
            <div id="section-dashboard" class="card">
                <div class="dashboard-header">
                    <h2>📊 Overview</h2>
                </div>

                <div class="dashboard">
                    <div class="dashboard-card">
                        <h3>🎓 Class Performance</h3>
                        <div class="stats">
                            <div>
                                <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                                <p>Total Students</p>
                            </div>
                            <div>
                                <div class="stat-number"><?php echo $stats['average_score']; ?>%</div>
                                <p>Average Quiz Score</p>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-card">
                        <h3>📖 Reading Progress</h3>
                        <div class="stats">
                            <div>
                                <div class="stat-number"><?php echo $stats['total_books_read']; ?></div>
                                <p>Total Books Read</p>
                            </div>
                            <div>
                                <div class="stat-number"><?php echo $stats['avg_books_per_student']; ?></div>
                                <p>Avg Books/Student</p>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-card">
                        <h3>🏆 Achievements</h3>
                        <div class="stats">
                            <div>
                                <div class="stat-number"><?php echo $stats['pens_given']; ?></div>
                                <p>Pen Rewards</p>
                            </div>
                            <div>
                                <div class="stat-number"><?php echo $stats['notebooks_given']; ?></div>
                                <p>Notebook Rewards</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

                <!-- Charts Section -->
                <div id="section-analytics" class="charts-section">
                    <div class="charts-header">
                        <h3>📈 Student Analytics</h3>
                    </div>
                    <div class="charts-grid">
                        <div class="chart-card">
                            <h4>🎯 Score Distribution</h4>
                            <div class="chart-container pie-chart">
                                <canvas id="scoresPieChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h4>🏆 Top Performing Students</h4>
                            <div class="top-students-list">
                                <?php if (count($chart_top_students_data) > 0): ?>
                                    <?php foreach ($chart_top_students_data as $index => $student): ?>
                                        <div class="student-rank-item">
                                            <div class="rank-badge <?php echo $index < 3 ? 'rank-' . ($index + 1) : 'rank-default'; ?>">
                                                <?php echo $index + 1; ?>
                                            </div>
                                            <div class="student-info">
                                                <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                                <div class="student-id-text"><?php echo htmlspecialchars($student['student_id']); ?></div>
                                            </div>
                                            <div class="student-stats">
                                                <div class="student-score"><?php echo $student['avg_score']; ?>%</div>
                                                <div class="student-books"><?php echo $student['books_read']; ?> books</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="text-align: center; padding: 40px; color: #999;">
                                        <div style="font-size: 48px; margin-bottom: 10px;">📚</div>
                                        <p>No student data available yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="chart-card">
                            <h4>📅 Monthly Quiz Activity</h4>
                            <div class="chart-container">
                                <canvas id="monthlyBarChart"></canvas>
                            </div>
                        </div>
                        <div class="chart-card-wide chart-card">
                            <h4>📚 Performance by Grade Level</h4>
                            <div class="chart-container">
                                <canvas id="gradeLevelChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Generate Report Section -->
                <div id="section-generate-report" class="card" style="margin-top: 30px;">
                    <div class="dashboard-header">
                        <h3>📄 Generate Report</h3>
                    </div>

                    <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: center;">
                        <form id="generateReportForm" style="flex: 1; min-width: 320px;">
                            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                                <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="includeScoresChart" checked> Score Distribution</label>
                                <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="includeMonthlyChart" checked> Monthly Activity</label>
                                <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="includeGradeChart" checked> Grade Level Performance</label>
                                <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="includeTopStudentsChart"> Top Students</label>
                                <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" id="includeStats" checked> Include Summary Stats</label>
                            </div>

                            <div style="display:flex; gap:12px; margin-top:12px; align-items:center; flex-wrap:wrap;">
                                <!-- Date range inputs removed per request -->
                                <button id="generateReportBtn" class="btn" style="margin-left:8px;">📤 Generate PDF</button>
                                <button id="exportCsvBtn" class="btn btn-secondary" style="margin-left:8px;">📥 Export CSV</button>
                            </div>
                        </form>

                        <!-- Notes section removed as requested -->
                    </div>
                </div>

                <div id="section-quiz-management">
                <h3 style="margin-top: 30px;">📝 Quiz Management</h3>
                <div class="table-wrapper" style="margin-top: 15px;">
                    <table class="table" id="quizManagementTable">
                        <thead>
                            <tr>
                                <th>Book ID</th>
                                <th>Title</th>
                                <th>Author</th>
                                <th>QR Code</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($all_books) > 0): ?>
                                <?php foreach ($all_books as $book): ?>
                                <tr>
                                    <td><?php echo $book['book_id']; ?></td>
                                    <td><?php echo htmlspecialchars($book['title']); ?></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo htmlspecialchars($book['qr_code']); ?></td>
                                    <td>
                                        <button class="btn btn-small" onclick="openQuizEditor(<?php echo $book['book_id']; ?>, '<?php echo addslashes($book['title']); ?>')">
                                            ✏️ Edit Quiz
                                        </button>
                                        <button class="btn btn-small" style="background: #dc3545;" onclick="deleteEntireQuiz(<?php echo $book['book_id']; ?>, '<?php echo addslashes($book['title']); ?>')">
                                            🗑️ Delete Quiz
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 30px; color: #999;">
                                        📚 No books found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </div> <!-- End Quiz Management Section -->

                <div id="section-student-progress">
                <h3 style="margin-top: 40px;">👥 Student Progress Tracking</h3>

                <div class="table-wrapper">
                    <table class="table" id="studentProgressTable">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Student ID</th>
                                <th>Book Title</th>
                                <th>Quiz Score</th>
                                <th>Percentage</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($student_progress->num_rows > 0): ?>
                                <?php while($row = $student_progress->fetch_assoc()): ?>
                                    <?php
                                    $percentage = $row['score_percentage'];
                                    $score_class = $percentage >= 80 ? 'score-high' : ($percentage >= 60 ? 'score-medium' : 'score-low');
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['student_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['book_title']); ?></td>
                                        <td><?php echo $row['correct_answers'] . '/' . $row['total_questions']; ?></td>
                                        <td>
                                            <span class="score-badge <?php echo $score_class; ?>">
                                                <?php echo number_format($percentage, 1); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $row['status']; ?>">
                                                <?php echo ucfirst($row['status']); ?>
                                                <?php if ($row['tab_switch_count'] > 0): ?>
                                                    (⚠️ <?php echo $row['tab_switch_count']; ?> switches)
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $row['formatted_date']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 30px; color: #999;">
                                        📭 No student progress records found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </div> <!-- End Student Progress Section -->
            </div>

        <div id="quizEditorModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="quizEditorTitle">Edit Quiz Questions</h2>
                    <button class="close-modal" onclick="closeQuizEditor()">&times;</button>
                </div>
                <div id="quizEditorBody" style="max-height: 60vh; overflow-y: auto;">
                    <p style="text-align: center; padding: 20px;">Loading questions...</p>
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <button class="btn" onclick="saveQuizChanges()">💾 Save Changes</button>
                    <button class="btn btn-secondary" onclick="closeQuizEditor()">Cancel</button>
                </div>
            </div>
        </div>

        <script>
            // ===== Chart.js Initialization =====
            
            // Chart Data from PHP
            const scoresData = <?php echo json_encode($chart_scores_data); ?>;
            const monthlyData = <?php echo json_encode($chart_monthly_data); ?>;
            const gradeData = <?php echo json_encode($chart_grade_data); ?>;
            
            // Color Palettes
            const pieColors = [
                'rgba(40, 167, 69, 0.85)',
                'rgba(23, 162, 184, 0.85)',
                'rgba(255, 193, 7, 0.85)',
                'rgba(220, 53, 69, 0.85)'
            ];

            // Initialize Charts
            let scoresChart, monthlyChart, gradeLevelChart;

            function initializeCharts() {
                // Pie Chart: Score Distribution
                const scoresCtx = document.getElementById('scoresPieChart');
                if (scoresCtx) {
                    scoresChart = new Chart(scoresCtx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: scoresData.map(item => item.score_range),
                            datasets: [{
                                data: scoresData.map(item => item.count),
                                backgroundColor: pieColors,
                                borderColor: 'white',
                                borderWidth: 3,
                                hoverOffset: 10
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: {
                                        padding: 15,
                                        usePointStyle: true,
                                        font: { size: 11 }
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((context.raw / total) * 100).toFixed(1);
                                            return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Bar Chart: Monthly Activity
                const monthlyCtx = document.getElementById('monthlyBarChart');
                if (monthlyCtx) {
                    monthlyChart = new Chart(monthlyCtx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: monthlyData.map(item => item.month_label),
                            datasets: [{
                                label: 'Quiz Attempts',
                                data: monthlyData.map(item => item.attempts),
                                backgroundColor: 'rgba(102, 126, 234, 0.8)',
                                borderRadius: 8,
                                yAxisID: 'y'
                            }, {
                                label: 'Avg Score (%)',
                                data: monthlyData.map(item => item.avg_score),
                                type: 'line',
                                borderColor: 'rgba(40, 167, 69, 1)',
                                borderWidth: 3,
                                pointRadius: 6,
                                fill: false,
                                yAxisID: 'y1'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            scales: {
                                y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Attempts' } },
                                y1: { min: 0, max: 100, position: 'right', title: { display: true, text: 'Avg Score (%)' }, grid: { drawOnChartArea: false } }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        afterBody: function(context) {
                                            const dataIndex = context[0].dataIndex;
                                            const item = monthlyData[dataIndex];
                                            return ['Students: ' + (item.unique_students || 0)];
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // Bar Chart: Grade Level Performance
                const gradeCtx = document.getElementById('gradeLevelChart');
                if (gradeCtx) {
                    gradeLevelChart = new Chart(gradeCtx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: gradeData.map(item => 'Grade ' + item.grade_level),
                            datasets: [
                                {
                                    label: 'Total Attempts',
                                    data: gradeData.map(item => item.total_attempts),
                                    backgroundColor: 'rgba(102, 126, 234, 0.85)',
                                    borderColor: 'rgba(102, 126, 234, 1)',
                                    borderWidth: 2,
                                    borderRadius: 6,
                                    order: 2
                                },
                                {
                                    label: 'Passing Attempts',
                                    data: gradeData.map(item => item.passing_attempts),
                                    backgroundColor: 'rgba(40, 167, 69, 0.85)',
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    borderWidth: 2,
                                    borderRadius: 6,
                                    order: 3
                                },
                                {
                                    label: 'Unique Students',
                                    data: gradeData.map(item => item.unique_students),
                                    backgroundColor: 'rgba(255, 193, 7, 0.85)',
                                    borderColor: 'rgba(255, 193, 7, 1)',
                                    borderWidth: 2,
                                    borderRadius: 6,
                                    order: 4
                                },
                                {
                                    label: 'Pass Rate (%)',
                                    data: gradeData.map(item => item.pass_rate || 0),
                                    type: 'line',
                                    borderColor: 'rgba(220, 53, 69, 1)',
                                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                                    borderWidth: 3,
                                    pointRadius: 6,
                                    pointBackgroundColor: 'rgba(220, 53, 69, 1)',
                                    fill: false,
                                    yAxisID: 'y1',
                                    order: 1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        padding: 15,
                                        usePointStyle: true,
                                        font: { size: 11 }
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        afterBody: function(context) {
                                            const dataIndex = context[0].dataIndex;
                                            const item = gradeData[dataIndex];
                                            return ['Avg Score: ' + (item.avg_score || 0) + '%'];
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    position: 'left',
                                    title: { display: true, text: 'Count' },
                                    ticks: { stepSize: 1 }
                                },
                                y1: {
                                    min: 0,
                                    max: 100,
                                    position: 'right',
                                    title: { display: true, text: 'Pass Rate (%)' },
                                    grid: { drawOnChartArea: false }
                                }
                            }
                        }
                    });
                }
            }

            // Initialize charts when page loads
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(initializeCharts, 300);
            });

            // Load quiz results from localStorage
            function loadQuizResults() {
                const results = JSON.parse(localStorage.getItem('quizResults') || '[]');
                const tableBody = document.getElementById('studentProgressTable');
                
                // Add new results to the table
                results.forEach(result => {
                    const row = document.createElement('tr');
                    row.style.backgroundColor = '#fffacd'; // Highlight new entries
                    row.innerHTML = `
                        <td>${result.studentName}</td>
                        <td>${result.studentId}</td>
                        <td>${result.bookTitle}</td>
                        <td>${result.score}/${result.totalQuestions}</td>
                        <td>${result.percentage}%</td>
                        <td>${result.date}</td>
                    `;
                    tableBody.insertBefore(row, tableBody.firstChild);
                });
            }

            // Load results when page loads
            loadQuizResults();

            let currentBookId = null;
            let questionsData = [];

            async function deleteQuestion(questionId) {
                if (!confirm('⚠️ Are you sure you want to delete this question?')) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('delete_question', '1');
                formData.append('question_id', questionId);
                
                try {
                    const response = await fetch('teacher.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        alert('✅ Question deleted successfully!');
                        closeQuizEditor();
                        location.reload();
                    } else {
                        alert('❌ ' + data.message);
                    }
                } catch (error) {
                    console.error('Error deleting question:', error);
                    alert('❌ Error deleting question. Please try again.');
                }
            }

            let currentQuizTimeLimit = 0;
            let currentQuizMaxAttempts = null;

            async function openQuizEditor(bookId, bookTitle) {
                currentBookId = bookId;
                document.getElementById('quizEditorTitle').textContent = `Edit Quiz: ${bookTitle}`;
                document.getElementById('quizEditorModal').classList.add('active');
                
                try {
                    const response = await fetch(`get-quiz-questions.php?book_id=${bookId}`);
                    const data = await response.json();
                    
                    // Store timer and attempts data
                    currentQuizTimeLimit = data.quiz_time_limit || 0;
                    currentQuizMaxAttempts = (data.max_quiz_attempts !== undefined) ? data.max_quiz_attempts : null;
                    
                    questionsData = data.questions || [];
                    renderQuizEditor(questionsData, bookTitle);
                } catch (error) {
                    console.error('Error loading questions:', error);
                    // Show add/upload sections even on error
                    renderQuizEditor([], bookTitle);
                }
            }

            async function deleteEntireQuiz(bookId, bookTitle) {
                if (!confirm(`⚠️ Are you sure you want to DELETE ALL QUIZ QUESTIONS for "${bookTitle}"?\n\nThis will permanently delete:\n• ALL quiz questions for this book\n• This will NOT delete the book itself\n• Students can no longer take the quiz\n\nThis action CANNOT be undone!`)) {
                    return;
                }
                
                const formData = new FormData();
                formData.append('delete_quiz', '1');
                formData.append('book_id', bookId);
                
                try {
                    const response = await fetch('teacher.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        alert('✅ ' + data.message);
                        // Force page reload to reflect changes
                        window.location.reload();
                    } else {
                        alert('❌ ' + data.message);
                    }
                } catch (error) {
                    console.error('Error deleting quiz:', error);
                    alert('❌ Error deleting quiz. Please try again.');
                }
            }

            let currentQuizPage = 1;
            const questionsPerPage = 10;
            let allQuestions = [];

            function renderQuizEditor(questions, bookTitle) {
                allQuestions = questions;
                currentQuizPage = 1;
                renderQuizPage();
            }

            function renderQuizPage() {
                const editorBody = document.getElementById('quizEditorBody');
                const start = (currentQuizPage - 1) * questionsPerPage;
                const end = start + questionsPerPage;
                const pageQuestions = allQuestions.slice(start, end);
                const totalPages = Math.ceil(allQuestions.length / questionsPerPage);
                
                // Parse timer values
                const hours = Math.floor(currentQuizTimeLimit / 3600);
                const minutes = Math.floor((currentQuizTimeLimit % 3600) / 60);
                const seconds = currentQuizTimeLimit % 60;
                
                let html = '';
                
                // Quiz Timer Settings
                html += `
                    <div style="background: #e3f2fd; border: 2px solid #2196f3; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #1565c0;">⏱️ Quiz Timer Settings</h4>
                        <p style="margin: 0 0 15px 0; color: #666; font-size: 0.9em;">Set a time limit for this quiz. Leave all fields at 0 for unlimited time.</p>
                        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <input type="number" id="timerHours" value="${hours}" min="0" max="24" style="width: 70px; padding: 8px; border: 2px solid #ddd; border-radius: 6px; text-align: center;">
                                <span style="color: #666;">Hours</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <input type="number" id="timerMinutes" value="${minutes}" min="0" max="59" style="width: 70px; padding: 8px; border: 2px solid #ddd; border-radius: 6px; text-align: center;">
                                <span style="color: #666;">Minutes</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <input type="number" id="timerSeconds" value="${seconds}" min="0" max="59" style="width: 70px; padding: 8px; border: 2px solid #ddd; border-radius: 6px; text-align: center;">
                                <span style="color: #666;">Seconds</span>
                            </div>
                            <button type="button" class="btn" style="background: #2196f3;" onclick="saveQuizTimer()">💾 Save Timer</button>
                        </div>
                        <p id="timerStatus" style="margin: 10px 0 0 0; font-size: 0.85em; color: ${currentQuizTimeLimit > 0 ? '#388e3c' : '#666'};">
                            ${currentQuizTimeLimit > 0 ? `✓ Timer set: ${hours}h ${minutes}m ${seconds}s` : 'No time limit set'}
                        </p>
                    </div>

                    <!-- Max Attempts Settings -->
                    <div style="background: #fff8e1; border: 2px solid #ffb300; padding: 20px; border-radius: 10px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #ff8f00;">🎯 Max Attempts Per Student</h4>
                        <p style="margin: 0 0 15px 0; color: #666; font-size: 0.9em;">Limit how many times each student can take this quiz. Leave blank or set to 0 for unlimited attempts.</p>
                        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <input type="number" id="maxAttempts" value="${currentQuizMaxAttempts !== null ? currentQuizMaxAttempts : ''}" min="0" style="width: 120px; padding: 8px; border: 2px solid #ddd; border-radius: 6px; text-align: center;">
                                <span style="color: #666;">Attempts (0 = unlimited)</span>
                            </div>
                            <button type="button" class="btn" style="background: #ffb300; color: #222;" onclick="saveMaxAttempts()">💾 Save Attempts</button>
                        </div>
                        <p id="attemptsStatus" style="margin: 10px 0 0 0; font-size: 0.85em; color: ${currentQuizMaxAttempts && currentQuizMaxAttempts > 0 ? '#388e3c' : '#666'};">
                            ${currentQuizMaxAttempts && currentQuizMaxAttempts > 0 ? `✓ Max attempts set: ${currentQuizMaxAttempts}` : 'No attempt limit set'}
                        </p>
                    </div>
                        </p>
                    </div>
                `;
                
                // Delete quiz button
                if (allQuestions.length > 0) {
                    html += `
                        <div style="background: #fff3cd; border: 2px solid #ffc107; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h4 style="margin: 0 0 5px 0; color: #856404;">⚠️ Danger Zone</h4>
                                    <p style="margin: 0; color: #856404; font-size: 0.9em;">Delete all ${allQuestions.length} question(s) for this book</p>
                                </div>
                                <button type="button" class="btn btn-small" style="background: #dc3545;" onclick="deleteEntireQuiz(${currentBookId}, '${document.getElementById('quizEditorTitle').textContent.replace('Edit Quiz: ', '').replace(/'/g, "\\'")}')">
                                    🗑️ Delete Entire Quiz
                                </button>
                            </div>
                        </div>
                    `;
                }
                
                if (pageQuestions.length > 0) {
                    html += '<form id="quizEditorForm">';
                    
                    pageQuestions.forEach((q, index) => {
                        const globalIndex = start + index + 1;
                        const questionType = q.question_type || 'multiple_choice';
                        
                        html += `
                            <div class="question-block">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <h4 style="margin: 0;">Question ${globalIndex}</h4>
                                    <button type="button" class="btn btn-small" style="background: #dc3545; padding: 5px 10px;" onclick="deleteQuestion(${q.question_id})">
                                        🗑️ Delete
                                    </button>
                                </div>
                                
                                <label>Question Type:</label>
                                <select name="questions[${q.question_id}][type]" class="filter-select" style="margin-bottom: 10px;" onchange="updateQuestionType(${q.question_id}, this.value)">
                                    <option value="multiple_choice" ${questionType === 'multiple_choice' ? 'selected' : ''}>Multiple Choice</option>
                                    <option value="identification" ${questionType === 'identification' ? 'selected' : ''}>Identification</option>
                                    <option value="fill_blank" ${questionType === 'fill_blank' ? 'selected' : ''}>Fill in the Blanks</option>
                                    <option value="enumeration" ${questionType === 'enumeration' ? 'selected' : ''}>Enumeration</option>
                                </select>
                                
                                <label>Question Text:</label>
                                <textarea name="questions[${q.question_id}][text]" required>${q.question_text}</textarea>
                                
                                <div id="answer-section-${q.question_id}">
                                    ${renderAnswerSection(q, questionType)}
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</form>';
                    
                    // Pagination for questions
                    if (totalPages > 1) {
                        html += '<div class="pagination" style="margin: 20px 0;">';
                        if (currentQuizPage > 1) {
                            html += `<button class="pagination-btn" onclick="changeQuizPage(${currentQuizPage - 1})">‹ Prev</button>`;
                        }
                        html += `<span class="pagination-info">Page ${currentQuizPage} of ${totalPages}</span>`;
                        if (currentQuizPage < totalPages) {
                            html += `<button class="pagination-btn" onclick="changeQuizPage(${currentQuizPage + 1})">Next ›</button>`;
                        }
                        html += '</div>';
                    }
                }
                
                // Add new question section
                html += `
                    <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #667eea;">
                        <h3>➕ Add New Question</h3>
                        <form id="addQuestionForm">
                            <div class="question-block">
                                <label>Question Type:</label>
                                <select id="newQuestionType" class="filter-select" onchange="updateNewQuestionType()">
                                    <option value="multiple_choice">Multiple Choice</option>
                                    <option value="identification">Identification</option>
                                    <option value="fill_blank">Fill in the Blanks</option>
                                    <option value="enumeration">Enumeration</option>
                                </select>
                                
                                <label>Question Text:</label>
                                <textarea id="newQuestionText" placeholder="Enter your question here..." required></textarea>
                                
                                <div id="newAnswerSection">
                                    ${renderNewAnswerSection('multiple_choice')}
                                </div>
                                
                                <label>Difficulty:</label>
                                <select id="newDifficulty" class="filter-select">
                                    <option value="easy">Easy</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                                <button type="button" class="btn" onclick="addNewQuestion()">➕ Add Question</button>
                            </div>
                        </form>
                    </div>
                `;
                
                // Upload section
                html += `
                    <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #667eea;">
                        <h3>📤 Upload Questions from File</h3>
                        ${renderUploadSection()}
                    </div>
                `;
                
                editorBody.innerHTML = html;
                setTimeout(() => initializeDragAndDrop(), 100);
            }

            function renderAnswerSection(question, type) {
                if (type === 'multiple_choice') {
                    return `
                        <label>Options:</label>
                        <div class="options-grid">
                            <div class="option-input">
                                <input type="radio" name="questions[${question.question_id}][correct]" value="A" ${question.correct_answer === 'A' ? 'checked' : ''}>
                                <input type="text" name="questions[${question.question_id}][option_a]" value="${question.option_a || ''}" placeholder="Option A" required>
                            </div>
                            <div class="option-input">
                                <input type="radio" name="questions[${question.question_id}][correct]" value="B" ${question.correct_answer === 'B' ? 'checked' : ''}>
                                <input type="text" name="questions[${question.question_id}][option_b]" value="${question.option_b || ''}" placeholder="Option B" required>
                            </div>
                            <div class="option-input">
                                <input type="radio" name="questions[${question.question_id}][correct]" value="C" ${question.correct_answer === 'C' ? 'checked' : ''}>
                                <input type="text" name="questions[${question.question_id}][option_c]" value="${question.option_c || ''}" placeholder="Option C" required>
                            </div>
                            <div class="option-input">
                                <input type="radio" name="questions[${question.question_id}][correct]" value="D" ${question.correct_answer === 'D' ? 'checked' : ''}>
                                <input type="text" name="questions[${question.question_id}][option_d]" value="${question.option_d || ''}" placeholder="Option D" required>
                            </div>
                        </div>
                    `;
                } else if (type === 'enumeration') {
                    const answers = (question.correct_answer || '').split(',').filter(a => a.trim());
                    return `
                        <label>Correct Answers (one per field):</label>
                        <div class="enumeration-answers" id="enum-${question.question_id}">
                            ${answers.map((ans, i) => `
                                <div class="enum-answer-row">
                                    <input type="text" name="questions[${question.question_id}][enum][]" value="${ans.trim()}" placeholder="Answer ${i + 1}" required>
                                    ${i > 0 ? `<button type="button" class="btn-remove-enum" onclick="this.parentElement.remove()">✖</button>` : ''}
                                </div>
                            `).join('')}
                        </div>
                        <button type="button" class="btn-add-enum" onclick="addEnumAnswer(${question.question_id})">+ Add Answer</button>
                    `;
                } else {
                    return `
                        <label>Correct Answer:</label>
                        <input type="text" name="questions[${question.question_id}][answer]" value="${question.correct_answer || ''}" placeholder="Enter correct answer" required>
                    `;
                }
            }

            function renderNewAnswerSection(type) {
                if (type === 'multiple_choice') {
                    return `
                        <label>Options:</label>
                        <div class="options-grid">
                            <div class="option-input">
                                <input type="radio" name="newCorrect" value="A" required>
                                <input type="text" id="newOptionA" placeholder="Option A" required>
                            </div>
                            <div class="option-input">
                                <input type="radio" name="newCorrect" value="B">
                                <input type="text" id="newOptionB" placeholder="Option B" required>
                            </div>
                            <div class="option-input">
                                <input type="radio" name="newCorrect" value="C">
                                <input type="text" id="newOptionC" placeholder="Option C" required>
                            </div>
                            <div class="option-input">
                                <input type="radio" name="newCorrect" value="D">
                                <input type="text" id="newOptionD" placeholder="Option D" required>
                            </div>
                        </div>
                    `;
                } else if (type === 'enumeration') {
                    return `
                        <label>Correct Answers (one per field):</label>
                        <div class="enumeration-answers" id="new-enum-answers">
                            <div class="enum-answer-row">
                                <input type="text" class="new-enum-answer" placeholder="Answer 1" required>
                            </div>
                        </div>
                        <button type="button" class="btn-add-enum" onclick="addNewEnumAnswer()">+ Add Answer</button>
                    `;
                } else {
                    return `
                        <label>Correct Answer:</label>
                        <input type="text" id="newAnswer" placeholder="Enter correct answer" required>
                    `;
                }
            }

            function renderUploadSection() {
                return `
                    <form id="uploadQuestionsForm" enctype="multipart/form-data">
                        <div class="question-block">
                            <div style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                                <h4 style="margin: 0 0 15px 0; color: #1976d2;">📋 Text File Format Instructions</h4>
                                <p style="margin: 0 0 10px 0; color: #333; font-weight: 600;">Your .txt file should follow this exact format:</p>
                                <pre style="background: white; padding: 15px; border-radius: 5px; overflow-x: auto; border: 1px solid #bbdefb; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6;">Q: What is the capital of France?
A) London
B) Paris
C) Berlin
D) Madrid
ANSWER: B

Q: Who wrote Romeo and Juliet?
A) Charles Dickens
B) Mark Twain
C) William Shakespeare
D) Jane Austen
ANSWER: C

Q: What is 2 + 2?
A) 3
B) 4
C) 5
D) 6
ANSWER: B</pre>
                                <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 5px;">
                                    <p style="margin: 0; color: #856404; font-size: 14px;"><strong>⚠️ Important Rules:</strong></p>
                                    <ul style="margin: 8px 0 0 20px; color: #856404; font-size: 13px;">
                                        <li>Start each question with "Q:" or "Question:"</li>
                                        <li>Options must be labeled A), B), C), D) or A., B., C., D.</li>
                                        <li>Answer line must start with "ANSWER:" or "Answer:"</li>
                                        <li>Answer must be A, B, C, or D (case insensitive)</li>
                                        <li>Leave one blank line between questions</li>
                                        <li>File size limit: 500KB</li>
                                        <li>Only .txt files are accepted</li>
                                    </ul>
                                </div>
                            </div>
                            
                            <input type="file" id="questionsFile" accept=".txt" class="file-input-hidden">
                            <div class="file-upload-area" id="fileUploadArea">
                                <div class="file-upload-icon">📁</div>
                                <div class="file-upload-text">
                                    <strong>Click to browse</strong> or drag and drop
                                </div>
                                <div class="file-upload-hint">Supported: .txt files only (Max 500KB)</div>
                            </div>
                            <div class="file-name-display" id="fileNameDisplay">
                                <span id="fileName"></span>
                                <span class="remove-file-btn" onclick="removeFile()">✖</span>
                            </div>
                            <button type="button" class="btn" onclick="uploadQuestions()" style="margin-top: 15px;">📤 Upload File</button>
                        </div>
                    </form>
                `;
            }

            function changeQuizPage(page) {
                currentQuizPage = page;
                renderQuizPage();
            }

            function updateQuestionType(questionId, type) {
                // Re-render answer section for this question
                const question = allQuestions.find(q => q.question_id == questionId);
                if (question) {
                    question.question_type = type;
                    const answerSection = document.getElementById(`answer-section-${questionId}`);
                    answerSection.innerHTML = renderAnswerSection(question, type);
                }
            }

            function updateNewQuestionType() {
                const type = document.getElementById('newQuestionType').value;
                document.getElementById('newAnswerSection').innerHTML = renderNewAnswerSection(type);
            }

            function addEnumAnswer(questionId) {
                const container = document.getElementById(`enum-${questionId}`);
                const count = container.querySelectorAll('.enum-answer-row').length + 1;
                const row = document.createElement('div');
                row.className = 'enum-answer-row';
                row.innerHTML = `
                    <input type="text" name="questions[${questionId}][enum][]" placeholder="Answer ${count}" required>
                    <button type="button" class="btn-remove-enum" onclick="this.parentElement.remove()">✖</button>
                `;
                container.appendChild(row);
            }

            function addNewEnumAnswer() {
                const container = document.getElementById('new-enum-answers');
                const count = container.querySelectorAll('.enum-answer-row').length + 1;
                const row = document.createElement('div');
                row.className = 'enum-answer-row';
                row.innerHTML = `
                    <input type="text" class="new-enum-answer" placeholder="Answer ${count}" required>
                    <button type="button" class="btn-remove-enum" onclick="this.parentElement.remove()">✖</button>
                `;
                container.appendChild(row);
            }

            function closeQuizEditor() {
                document.getElementById('quizEditorModal').classList.remove('active');
                currentBookId = null;
            }

            function initializeDragAndDrop() {
                const fileUploadArea = document.getElementById('fileUploadArea');
                const questionsFile = document.getElementById('questionsFile');
                
                if (fileUploadArea) {
                    fileUploadArea.addEventListener('click', () => {
                        questionsFile.click();
                    });
                }
                
                if (questionsFile) {
                    questionsFile.addEventListener('change', (event) => {
                        const fileName = event.target.files[0]?.name || '';
                        const fileNameDisplay = document.getElementById('fileNameDisplay');
                        const fileUploadArea = document.getElementById('fileUploadArea');
                        
                        if (fileNameDisplay) {
                            fileNameDisplay.classList.add('active');
                            fileNameDisplay.querySelector('#fileName').textContent = fileName;
                        }
                        
                        if (fileUploadArea) {
                            fileUploadArea.style.borderColor = '#28a745';
                        }
                    });
                }
            }

            function removeFile() {
                const fileInput = document.getElementById('questionsFile');
                const fileNameDisplay = document.getElementById('fileNameDisplay');
                const fileUploadArea = document.getElementById('fileUploadArea');
                
                if (fileInput) fileInput.value = '';
                if (fileNameDisplay) fileNameDisplay.classList.remove('active');
                if (fileUploadArea) fileUploadArea.style.borderColor = '#667eea';
            }

            async function saveQuizChanges() {
                const form = document.getElementById('quizEditorForm');
                if (!form) {
                    alert('❌ No questions to save.');
                    return;
                }
                
                // Manually build the form data to ensure question types are included
                const formData = new FormData();
                formData.append('update_questions', '1');
                formData.append('book_id', currentBookId);
                
                // Collect all form elements
                const formElements = form.elements;
                
                // Group data by question ID
                const questionsData = {};
                
                for (let element of formElements) {
                    const name = element.name;
                    if (!name) continue;
                    
                    // Parse the name: questions[123][type], questions[123][text], etc.
                    const match = name.match(/^questions\[(\d+)\]\[(\w+)\](?:\[\])?$/);
                    if (!match) continue;
                    
                    const questionId = match[1];
        const fieldName = match[2];
        
        if (!questionsData[questionId]) {
            questionsData[questionId] = {};
        }
        
        if (element.type === 'radio') {
            if (element.checked) {
                questionsData[questionId][fieldName] = element.value;
            }
        } else if (fieldName === 'enum') {
            // Collect enumeration answers
            if (!questionsData[questionId].enum) {
                questionsData[questionId].enum = [];
            }
            if (element.value.trim()) {
                questionsData[questionId].enum.push(element.value.trim());
            }
        } else {
            questionsData[questionId][fieldName] = element.value;
        }
    }
    
    // Convert to form data
    for (const [questionId, data] of Object.entries(questionsData)) {
        for (const [key, value] of Object.entries(data)) {
            if (key === 'enum') {
                // Send enumeration as multiple values
                value.forEach((v, idx) => {
                    formData.append(`questions[${questionId}][enum][${idx}]`, v);
                });
            } else {
                formData.append(`questions[${questionId}][${key}]`, value);
            }
        }
    }
    
    try {
        const response = await fetch('teacher.php', {
            method: 'POST',
            body: formData
        });
        
        const text = await response.text();
        let data;
        
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Response is not JSON:', text);
            alert('✅ Changes saved successfully!');
            closeQuizEditor();
            location.reload();
            return;
        }
        
        if (data.success) {
            alert('✅ Changes saved successfully!');
            closeQuizEditor();
            location.reload();
        } else {
            alert('❌ ' + (data.message || 'Failed to save changes.'));
        }
    } catch (error) {
        console.error('Error saving changes:', error);
        alert('❌ Error saving changes. Please try again.');
    }
}

            async function saveMaxAttempts() {
                const attemptsRaw = document.getElementById('maxAttempts').value;
                const attempts = attemptsRaw === '' ? 0 : parseInt(attemptsRaw) || 0;

                const formData = new FormData();
                formData.append('update_quiz_attempts', '1');
                formData.append('book_id', currentBookId);
                formData.append('max_attempts', attempts);

                try {
                    const response = await fetch('teacher.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        currentQuizMaxAttempts = attempts > 0 ? attempts : null;
                        const statusEl = document.getElementById('attemptsStatus');
                        if (attempts > 0) {
                            statusEl.style.color = '#388e3c';
                            statusEl.textContent = `✓ Max attempts saved: ${attempts}`;
                        } else {
                            statusEl.style.color = '#666';
                            statusEl.textContent = 'No attempt limit set';
                        }
                        alert('✅ Max attempts updated successfully!');
                    } else {
                        alert('❌ ' + (data.message || 'Failed to save max attempts.'));
                    }
                } catch (error) {
                    console.error('Error saving max attempts:', error);
                    alert('❌ Error saving max attempts. Please try again.');
                }
            }

            async function saveQuizTimer() {
                const hours = parseInt(document.getElementById('timerHours').value) || 0;
                const minutes = parseInt(document.getElementById('timerMinutes').value) || 0;
                const seconds = parseInt(document.getElementById('timerSeconds').value) || 0;
                
                const formData = new FormData();
                formData.append('update_quiz_timer', '1');
                formData.append('book_id', currentBookId);
                formData.append('hours', hours);
                formData.append('minutes', minutes);
                formData.append('seconds', seconds);
                
                try {
                    const response = await fetch('teacher.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        const totalSeconds = (hours * 3600) + (minutes * 60) + seconds;
                        currentQuizTimeLimit = totalSeconds;
                        const statusEl = document.getElementById('timerStatus');
                        if (totalSeconds > 0) {
                            statusEl.style.color = '#388e3c';
                            statusEl.textContent = `✓ Timer saved: ${hours}h ${minutes}m ${seconds}s`;
                        } else {
                            statusEl.style.color = '#666';
                            statusEl.textContent = 'No time limit set';
                        }
                        alert('✅ Quiz timer updated successfully!');
                    } else {
                        alert('❌ ' + (data.message || 'Failed to save timer.'));
                    }
                } catch (error) {
                    console.error('Error saving timer:', error);
                    alert('❌ Error saving timer. Please try again.');
                }
            }

            async function addNewQuestion() {
                const bookId = currentBookId;
                const questionType = document.getElementById('newQuestionType').value;
                const questionText = document.getElementById('newQuestionText').value.trim();
                const difficulty = document.getElementById('newDifficulty').value;
                
                if (!questionText) {
                    alert('❌ Please enter a question text.');
                    return;
                }
                
                const formData = new FormData();
                formData.append('add_question', '1');
                formData.append('book_id', bookId);
                formData.append('question_text', questionText);
                formData.append('question_type', questionType);
                formData.append('difficulty', difficulty);
                
                let isValid = true;
                
                if (questionType === 'multiple_choice') {
                    const correctAnswer = document.querySelector('input[name="newCorrect"]:checked');
                    const optionA = document.getElementById('newOptionA')?.value.trim();
                    const optionB = document.getElementById('newOptionB')?.value.trim();
                    const optionC = document.getElementById('newOptionC')?.value.trim();
                    const optionD = document.getElementById('newOptionD')?.value.trim();
                    
                    if (!correctAnswer) {
                        alert('❌ Please select a correct answer.');
                        return;
                    }
                    
                    if (!optionA || !optionB || !optionC || !optionD) {
                        alert('❌ Please fill in all options.');
                        return;
                    }
                    
                    formData.append('option_a', optionA);
                    formData.append('option_b', optionB);
                    formData.append('option_c', optionC);
                    formData.append('option_d', optionD);
                    formData.append('correct_answer', correctAnswer.value);
                    
                } else if (questionType === 'enumeration') {
                    const enumAnswers = document.querySelectorAll('.new-enum-answer');
                    const answers = [];
                    
                    enumAnswers.forEach(input => {
                        const value = input.value.trim();
                        if (value) {
                            answers.push(value);
                        }
                    });
                    
                    if (answers.length === 0) {
                        alert('❌ Please provide at least one answer for enumeration.');
                        return;
                    }
                    
                    formData.append('correct_answer', answers.join(','));
                    formData.append('option_a', '');
                    formData.append('option_b', '');
                    formData.append('option_c', '');
                    formData.append('option_d', '');
                    
                } else {
                    // identification or fill_blank
                    const answer = document.getElementById('newAnswer')?.value.trim();
                    
                    if (!answer) {
                        alert('❌ Please provide the correct answer.');
                        return;
                    }
                    
                    formData.append('correct_answer', answer);
                    formData.append('option_a', '');
                    formData.append('option_b', '');
                    formData.append('option_c', '');
                    formData.append('option_d', '');
                }
                
                try {
                    const response = await fetch('teacher.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const text = await response.text();
                    let data;
                    
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Response is not JSON:', text);
                        alert('✅ Question added successfully!');
                        closeQuizEditor();
                        location.reload();
                        return;
                    }
                    
                    if (data.success) {
                        alert('✅ Question added successfully!');
                        closeQuizEditor();
                        location.reload();
                    } else {
                        alert('❌ ' + (data.message || 'Failed to add question.'));
                    }
                } catch (error) {
                    console.error('Error adding question:', error);
                    alert('❌ Error adding question. Please try again.');
                }
            }

            async function uploadQuestions() {
                const fileInput = document.getElementById('questionsFile');
                
                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    alert('❌ Please select a file to upload.');
                    return;
                }
                
                const formData = new FormData();
                formData.append('upload_questions', '1');
                formData.append('book_id', currentBookId);
                formData.append('questions_file', fileInput.files[0]);
                
                try {
                    const response = await fetch('teacher.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        alert('✅ ' + data.message);
                        closeQuizEditor();
                        location.reload();
                    } else {
                        alert('❌ ' + data.message);
                    }
                } catch (error) {
                    console.error('Error uploading questions:', error);
                    alert('❌ Error uploading questions. Please try again.');
                }
            }

            window.addEventListener('load', function() {
                const bootLoader = document.getElementById('bootLoader');
                const mainContent = document.getElementById('mainContent');
                
                // Check if boot animation has been shown this session
                if (sessionStorage.getItem('bootAnimationShown')) {
                    bootLoader.style.display = 'none';
                    mainContent.classList.add('show');
                } else {
                    sessionStorage.setItem('bootAnimationShown', 'true');
                    setTimeout(() => {
                        bootLoader.classList.add('fade-out');
                        setTimeout(() => {
                            bootLoader.style.display = 'none';
                            mainContent.classList.add('show');
                        }, 800);
                    }, 1500);
                }
            });

            window.addEventListener('scroll', function() {
                const scrollProgressBar = document.getElementById('scrollProgressBar');
                const windowHeight = window.innerHeight;
                const documentHeight = document.documentElement.scrollHeight;
                const scrollTop = window.scrollY || document.documentElement.scrollTop;
                const scrollPercentage = (scrollTop / (documentHeight - windowHeight)) * 100;
                scrollProgressBar.style.width = scrollPercentage + '%';
            });

            // Sidebar navigation - scroll to section
            function scrollToSection(sectionId) {
                // Update active state in sidebar
                document.querySelectorAll('.sidebar-menu a').forEach(link => {
                    link.classList.remove('active');
                });
                event.target.closest('a').classList.add('active');
                
                // Scroll to section
                const section = document.getElementById('section-' + sectionId);
                if (section) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }

            // ===== Generate Report (Teacher) =====
            const teacherStats = <?php echo json_encode($stats); ?>;

            async function generateReport() {
                const btn = document.getElementById('generateReportBtn');
                btn.disabled = true;
                btn.textContent = 'Generating...';

                const includeScores = document.getElementById('includeScoresChart').checked;
                const includeMonthly = document.getElementById('includeMonthlyChart').checked;
                const includeGrade = document.getElementById('includeGradeChart').checked;
                const includeTop = document.getElementById('includeTopStudentsChart').checked;
                const includeStats = document.getElementById('includeStats').checked;
                const startEl = document.getElementById('reportStartDate');
                const endEl = document.getElementById('reportEndDate');
                const startDate = startEl && startEl.value ? startEl.value : null;
                const endDate = endEl && endEl.value ? endEl.value : null;

                const charts = [];

                // Helper: extract canvas dataurl if exists
                function canvasDataUrl(id) {
                    const c = document.getElementById(id);
                    if (c && c.toDataURL) return c.toDataURL('image/png');
                    return null;
                }

                if (includeScores) {
                    const data = canvasDataUrl('scoresPieChart');
                    if (data) charts.push({ name: 'Score Distribution', data });
                }
                if (includeMonthly) {
                    const data = canvasDataUrl('monthlyBarChart');
                    if (data) charts.push({ name: 'Monthly Quiz Activity', data });
                }
                if (includeGrade) {
                    const data = canvasDataUrl('gradeLevelChart');
                    if (data) charts.push({ name: 'Grade Level Performance', data });
                }

                // Top students: try to render a simple image from HTML list if requested
                if (includeTop) {
                    const list = document.querySelector('.top-students-list');
                    if (list) {
                        try {
                            // Create offscreen canvas
                            const lines = [];
                            list.querySelectorAll('.student-rank-item').forEach(el => {
                                const name = el.querySelector('.student-name')?.innerText || '';
                                const id = el.querySelector('.student-id-text')?.innerText || '';
                                const score = el.querySelector('.student-score')?.innerText || '';
                                lines.push(`${name} | ${id} | ${score}`);
                            });
                            if (lines.length) {
                                const canvas = document.createElement('canvas');
                                const w = 800; const lineH = 20; const h = Math.max(100, lines.length * lineH + 30);
                                canvas.width = w; canvas.height = h; const ctx = canvas.getContext('2d');
                                ctx.fillStyle = '#ffffff'; ctx.fillRect(0,0,w,h);
                                ctx.font = '14px Arial'; ctx.fillStyle = '#222';
                                ctx.fillText('Top Students', 12, 22);
                                ctx.font = '12px Arial';
                                lines.forEach((ln, idx) => ctx.fillText(ln, 12, 50 + idx * lineH));
                                charts.push({ name: 'Top Students', data: canvas.toDataURL('image/png') });
                            }
                        } catch (e) {
                            console.warn('Could not render top students image', e);
                        }
                    }
                }

                const payload = {
                    charts,
                    stats: teacherStats,
                    options: { includeStats, startDate, endDate }
                };

                try {
                    const resp = await fetch('generate_report_pdf.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });

                    if (!resp.ok) throw new Error('Server error');

                    const blob = await resp.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'LibraryHub_Report_' + new Date().toISOString().slice(0,10) + '.pdf';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                } catch (err) {
                    console.error('Report generation failed', err);
                    alert('❌ Failed to generate report. Check server logs.');
                } finally {
                    btn.disabled = false;
                    btn.textContent = '📤 Generate PDF';
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                const btn = document.getElementById('generateReportBtn');
                if (btn) btn.addEventListener('click', function(e){ e.preventDefault(); generateReport(); });
                const csvBtn = document.getElementById('exportCsvBtn');
                if (csvBtn) csvBtn.addEventListener('click', function(e){ e.preventDefault(); exportStudentProgressCSV(); });
            });

            function exportStudentProgressCSV() {
                const table = document.getElementById('studentProgressTable');
                if (!table) { alert('No student progress table found to export.'); return; }

                const rows = [];
                const headers = [];
                table.querySelectorAll('thead th').forEach(th => headers.push(th.innerText.trim()));
                rows.push(headers.join(','));

                table.querySelectorAll('tbody tr').forEach(tr => {
                    // skip empty placeholder rows
                    if (tr.querySelectorAll('td').length === 1) return;
                    const cols = [];
                    tr.querySelectorAll('td').forEach(td => {
                        let txt = td.innerText.replace(/\r?\n|\t/g, ' ').trim();
                        // escape double quotes
                        txt = txt.replace(/"/g, '""');
                        // wrap in quotes if contains comma or quote
                        if (txt.indexOf(',') !== -1 || txt.indexOf('"') !== -1) txt = '"' + txt + '"';
                        cols.push(txt);
                    });
                    if (cols.length) rows.push(cols.join(','));
                });

                const csvContent = rows.join('\r\n');
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'student_progress_' + new Date().toISOString().slice(0,10) + '.csv';
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
            }
        </script>
        
        <!-- Vendor scripts (local for offline use) -->
        <script src="vendor/jquery-3.7.1.min.js"></script>
        <script src="vendor/jquery.dataTables.min.js"></script>
        <script src="vendor/sweetalert2.min.js"></script>
        <!-- Error Handler -->
        <script src="../js/error-handler.js"></script>
        <script>
            $(document).ready(function() {
                // Initialize Quiz Management Table
                if ($('#quizManagementTable').length) {
                    $('#quizManagementTable').DataTable({
                        pageLength: 10,
                        lengthMenu: [[10, 25, 30, -1], [10, 25, 30, "All"]],
                        order: [[0, 'asc']],
                        columnDefs: [
                            { orderable: false, targets: [-1, -2] }
                        ],
                        language: {
                            search: "🔍 Search:",
                            lengthMenu: "Show _MENU_ books",
                            info: "Showing _START_ to _END_ of _TOTAL_ books",
                            emptyTable: "No books found"
                        }
                    });
                }

                // Initialize Student Progress Table
                if ($('#studentProgressTable').length) {
                    $('#studentProgressTable').DataTable({
                        pageLength: 10,
                        lengthMenu: [[10, 25, 30, -1], [10, 25, 30, "All"]],
                        order: [[6, 'desc']],
                        language: {
                            search: "🔍 Search:",
                            lengthMenu: "Show _MENU_ entries",
                            info: "Showing _START_ to _END_ of _TOTAL_ attempts",
                            emptyTable: "No quiz attempts found"
                        }
                    });
                }
            });
        </script>
        </main>
    </div>
</body>
</html>
