<?php
require_once 'config.php';

// Check if user is logged in as librarian
check_user_type(['librarian']);

$message = '';
$error = '';

// Handle book addition form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_book'])) {
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $genre = trim($_POST['genre'] ?? '');
    $recommended_grade = trim($_POST['recommended_grade'] ?? '');
    $total_pages = (int)($_POST['total_pages'] ?? 0);
    $difficulty = $_POST['difficulty'] ?? 'beginner';
    $description = trim($_POST['description'] ?? '');
    $qr_code = trim($_POST['qr_code'] ?? '');

    // Validate required fields
    if (!$title || !$author || !$qr_code) {
        $error = 'Title, Author, and QR Code are required!';
    } else {
        // Check if QR code already exists
        $check_qr_sql = "SELECT book_id FROM books WHERE qr_code = ?";
        $check_qr_stmt = $conn->prepare($check_qr_sql);
        $check_qr_stmt->bind_param("s", $qr_code);
        $check_qr_stmt->execute();
        $check_qr_result = $check_qr_stmt->get_result();
        
        if ($check_qr_result->num_rows > 0) {
            $error = '❌ QR Code already exists! Please use a unique QR code.';
            $check_qr_stmt->close();
        } else {
            $check_qr_stmt->close();
            
            try {
                // Create qr_codes directory if it doesn't exist
                $qr_dir = __DIR__ . '/../qr_codes';
                if (!file_exists($qr_dir)) {
                    mkdir($qr_dir, 0755, true);
                }
                
                $qr_code_path = "qr_codes/{$qr_code}.png";
                $qr_file_path = __DIR__ . '/../' . $qr_code_path;
                
                // Find Python executable
                $python_paths = [
                    'python',      // Try 'python' command first
                    'python3',     // Try 'python3' for Unix systems
                    'C:\\Python312\\python.exe',
                    'C:\\Python311\\python.exe',
                    'C:\\Python310\\python.exe',
                    'C:\\Python39\\python.exe',
                    'C:\\Users\\' . get_current_user() . '\\AppData\\Local\\Programs\\Python\\Python312\\python.exe',
                    'C:\\Users\\' . get_current_user() . '\\AppData\\Local\\Programs\\Python\\Python311\\python.exe',
                ];
                
                $python_cmd = null;
                foreach ($python_paths as $path) {
                    $test_output = shell_exec("\"$path\" --version 2>&1");
                    if ($test_output && stripos($test_output, 'python') !== false) {
                        $python_cmd = $path;
                        break;
                    }
                }
                
                $python_script = __DIR__ . '/../generate_single_qr.py';
                $qr_generated = false;
                
                if ($python_cmd) {
                    // Try Python script
                    $cmd = "\"$python_cmd\" \"$python_script\" \"$qr_code\" \"$qr_file_path\" 2>&1";
                    $output = shell_exec($cmd);
                    
                    if (file_exists($qr_file_path) && filesize($qr_file_path) > 0) {
                        $qr_generated = true;
                        $qr_method = "Python";
                    }
                }
                
                // Fallback to Google Charts API if Python failed
                if (!$qr_generated) {
                    $google_qr_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($qr_code) . "&choe=UTF-8";
                    $qr_image = @file_get_contents($google_qr_url);
                    
                    if ($qr_image !== false && strlen($qr_image) > 100) {
                        file_put_contents($qr_file_path, $qr_image);
                        $qr_generated = true;
                        $qr_method = "Google Charts API (Fallback)";
                    }
                }
                
                if (!$qr_generated) {
                    throw new Exception(
                        "Failed to generate QR code. Please:\n" .
                        "1. Install Python from https://www.python.org/downloads/\n" .
                        "2. Run: pip install qrcode[pil]\n" .
                        "3. Run test_qr_generation.py to verify installation\n" .
                        "4. Ensure internet connection for fallback method"
                    );
                }
                
                // Insert book into database
                $sql = "INSERT INTO books (title, author, qr_code, qr_code_path, genre, recommended_grade_level, total_pages, difficulty_level, description, is_available) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssiis", $title, $author, $qr_code, $qr_code_path, $genre, $recommended_grade, $total_pages, $difficulty, $description);
                
                if ($stmt->execute()) {
                    $book_id = $conn->insert_id;
                    $message = "✅ Book added successfully! Book ID: $book_id<br>🎯 QR Code generated: <strong>{$qr_code}.png</strong><br><small>Method: {$qr_method}</small>";
                    
                    // Log the action
                    $log_sql = "INSERT INTO system_logs (user_id, action, description) VALUES (?, 'book_added', ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $log_desc = "Added book: $title by $author (QR: $qr_code)";
                    $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
                    $log_stmt->execute();
                    $log_stmt->close();
                } else {
                    $error = 'Failed to add book to database. Please try again.';
                }
                $stmt->close();
                
            } catch (Exception $e) {
                $error = '❌ ' . nl2br($e->getMessage());
            }
        }
    }
}

// Handle book update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_book'])) {
    $book_id = (int)$_POST['book_id'];
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $genre = trim($_POST['genre'] ?? '');
    $recommended_grade = trim($_POST['recommended_grade'] ?? '');
    $total_pages = (int)($_POST['total_pages'] ?? 0);
    $difficulty = $_POST['difficulty'] ?? 'beginner';
    $description = trim($_POST['description'] ?? '');
    $is_available = isset($_POST['is_available']) ? 1 : 0;

    if (!$title || !$author) {
        $error = 'Title and Author are required!';
    } else {
        $sql = "UPDATE books SET title = ?, author = ?, genre = ?, recommended_grade_level = ?, 
                total_pages = ?, difficulty_level = ?, description = ?, is_available = ? 
                WHERE book_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssisiii", $title, $author, $genre, $recommended_grade, $total_pages, $difficulty, $description, $is_available, $book_id);
        
        if ($stmt->execute()) {
            $message = "✅ Book updated successfully!";
            
            // Log the action
            $log_sql = "INSERT INTO system_logs (user_id, action, description) VALUES (?, 'book_updated', ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_desc = "Updated book: $title (ID: $book_id)";
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            $error = 'Failed to update book. Please try again.';
        }
        $stmt->close();
    }
}

// Handle book deletion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_book'])) {
    $book_id = (int)$_POST['book_id'];
    
    // Check if book exists
    $check_sql = "SELECT title, qr_code_path FROM books WHERE book_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $book_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $book_data = $check_result->fetch_assoc();
        $book_title = $book_data['title'];
        $qr_code_path = $book_data['qr_code_path'];
        
        // Delete the book (CASCADE will delete related quiz questions, attempts, etc.)
        $delete_sql = "DELETE FROM books WHERE book_id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $book_id);
        
        if ($delete_stmt->execute()) {
            $qr_delete_status = '';
            
            // Delete QR code file if exists
            if ($qr_code_path) {
                $qr_file_full_path = __DIR__ . '/../' . $qr_code_path;
                
                if (file_exists($qr_file_full_path)) {
                    if (unlink($qr_file_full_path)) {
                        $qr_delete_status = "<br>🗑️ QR code image deleted: {$qr_code_path}";
                    } else {
                        $qr_delete_status = "<br>⚠️ Warning: Could not delete QR code image (check permissions)";
                        // Log the error
                        error_log("Failed to delete QR code: " . $qr_file_full_path);
                    }
                } else {
                    $qr_delete_status = "<br>ℹ️ QR code image file not found: {$qr_code_path}";
                }
            }
            
            $message = "✅ Book '{$book_title}' and all related data deleted successfully!{$qr_delete_status}";
            
            // Log the action
            $log_sql = "INSERT INTO system_logs (user_id, action, description) VALUES (?, 'book_deleted', ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_desc = "Deleted book: $book_title (ID: $book_id)" . ($qr_delete_status ? " - QR code: {$qr_code_path}" : "");
            $log_stmt->bind_param("is", $_SESSION['user_id'], $log_desc);
            $log_stmt->execute();
            $log_stmt->close();
        } else {
            $error = "❌ Failed to delete book. Please try again.";
        }
        $delete_stmt->close();
    } else {
        $error = "❌ Book not found.";
    }
    $check_stmt->close();
}

// Fetch statistics
$stats = [];

// Total books
$sql = "SELECT COUNT(*) as total FROM books";
$result = $conn->query($sql);
$stats['total_books'] = $result->fetch_assoc()['total'];

// Available books
$sql = "SELECT COUNT(*) as total FROM books WHERE is_available = 1";
$result = $conn->query($sql);
$stats['available_books'] = $result->fetch_assoc()['total'];

// Unavailable books
$stats['unavailable_books'] = $stats['total_books'] - $stats['available_books'];

// Total quiz attempts
$sql = "SELECT COUNT(*) as total FROM quiz_attempts";
$result = $conn->query($sql);
$stats['total_attempts'] = $result->fetch_assoc()['total'];

// ===== NEW: Chart Data Queries =====

// Data for Bar Chart: Quiz Attempts per Book (Top 10)
$sql = "SELECT 
            b.title,
            COUNT(qa.attempt_id) as attempts,
            ROUND(AVG(qa.score_percentage), 1) as avg_score
        FROM books b
        LEFT JOIN quiz_attempts qa ON b.book_id = qa.book_id
        GROUP BY b.book_id, b.title
        ORDER BY attempts DESC
        LIMIT 10";
$chart_attempts_result = $conn->query($sql);
$chart_attempts_data = [];
while ($row = $chart_attempts_result->fetch_assoc()) {
    $chart_attempts_data[] = $row;
}

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

// Data for Genre Distribution Pie Chart
$sql = "SELECT 
            IFNULL(NULLIF(genre, ''), 'Uncategorized') as genre,
            COUNT(*) as count
        FROM books
        GROUP BY genre
        ORDER BY count DESC
        LIMIT 8";
$chart_genre_result = $conn->query($sql);
$chart_genre_data = [];
while ($row = $chart_genre_result->fetch_assoc()) {
    $chart_genre_data[] = $row;
}

// Monthly Quiz Activity (Last 6 months)
$sql = "SELECT 
            DATE_FORMAT(attempt_date, '%Y-%m') as month,
            DATE_FORMAT(attempt_date, '%b %Y') as month_label,
            COUNT(*) as attempts,
            ROUND(AVG(score_percentage), 1) as avg_score
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
// First, get data for grades that exist in the database
$sql = "SELECT 
            grade_level,
            COALESCE(total_books, 0) as total_books,
            COALESCE(total_attempts, 0) as total_attempts,
            COALESCE(books_with_attempts, 0) as books_with_attempts,
            COALESCE(avg_score, 0) as avg_score,
            COALESCE(passing_attempts, 0) as passing_attempts,
            COALESCE(pass_rate, 0) as pass_rate
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
                ROUND((SUM(CASE WHEN qa.score_percentage >= 70 THEN 1 ELSE 0 END) / NULLIF(COUNT(qa.attempt_id), 0)) * 100, 1) as pass_rate
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

// ===== END NEW Chart Data Queries =====

// Best Performing Book (highest average score with minimum attempts)
$sql = "SELECT 
            b.book_id,
            b.title,
            b.author,
            b.genre,
            COUNT(qa.attempt_id) as total_attempts,
            COUNT(DISTINCT qa.user_id) as unique_readers,
            ROUND(AVG(qa.score_percentage), 1) as avg_score,
            ROUND((SUM(CASE WHEN qa.score_percentage >= 70 THEN 1 ELSE 0 END) / COUNT(qa.attempt_id)) * 100, 1) as pass_rate
        FROM books b
        INNER JOIN quiz_attempts qa ON b.book_id = qa.book_id
        GROUP BY b.book_id, b.title, b.author, b.genre
        HAVING total_attempts >= 3
        ORDER BY pass_rate DESC, avg_score DESC
        LIMIT 1";
$result = $conn->query($sql);
$best_book = $result->fetch_assoc();

// Most Challenging Book (lowest average score with minimum attempts)
$sql = "SELECT 
            b.book_id,
            b.title,
            b.author,
            b.genre,
            COUNT(qa.attempt_id) as total_attempts,
            COUNT(DISTINCT qa.user_id) as unique_readers,
            ROUND(AVG(qa.score_percentage), 1) as avg_score,
            ROUND((SUM(CASE WHEN qa.score_percentage >= 70 THEN 1 ELSE 0 END) / COUNT(qa.attempt_id)) * 100, 1) as pass_rate
        FROM books b
        INNER JOIN quiz_attempts qa ON b.book_id = qa.book_id
        GROUP BY b.book_id, b.title, b.author, b.genre
        HAVING total_attempts >= 3
        ORDER BY pass_rate ASC, avg_score ASC
        LIMIT 1";
$result = $conn->query($sql);
$challenging_book = $result->fetch_assoc();

// Pagination for Book Inventory
$books_per_page = 10;
$book_page = isset($_GET['book_page']) ? max(1, (int)$_GET['book_page']) : 1;
$book_offset = ($book_page - 1) * $books_per_page;

// Get total books count for pagination
$sql = "SELECT COUNT(*) as total FROM books";
$result = $conn->query($sql);
$total_books_count = $result->fetch_assoc()['total'];
$total_book_pages = ceil($total_books_count / $books_per_page);

// Book inventory with statistics (all books - DataTables handles pagination)
$sql = "SELECT 
            b.book_id,
            b.qr_code,
            b.title,
            b.author,
            b.genre,
            b.recommended_grade_level,
            b.total_pages,
            b.difficulty_level,
            b.description,
            b.is_available,
            COUNT(DISTINCT qa.user_id) as times_read,
            IFNULL(AVG(qa.score_percentage), 0) as avg_score
        FROM books b
        LEFT JOIN quiz_attempts qa ON b.book_id = qa.book_id
        GROUP BY b.book_id, b.qr_code, b.title, b.author, b.genre, b.recommended_grade_level, b.total_pages, b.difficulty_level, b.description, b.is_available
        ORDER BY b.title ASC";
$book_inventory_result = $conn->query($sql);

// Store books in array for later use
$book_inventory = [];
while ($row = $book_inventory_result->fetch_assoc()) {
    $book_inventory[] = $row;
}

// Get filter parameters for activity
$filter_book_id = isset($_GET['filter_book']) ? (int)$_GET['filter_book'] : 0;
$search_student_id = isset($_GET['search_student']) ? trim($_GET['search_student']) : '';

// Pagination for Activity
$activity_per_page = 10;
$activity_page = isset($_GET['activity_page']) ? max(1, (int)$_GET['activity_page']) : 1;
$activity_offset = ($activity_page - 1) * $activity_per_page;

// Count total activities for pagination
$activity_where = [];
$activity_params = [];
$activity_types = "";

if ($filter_book_id > 0) {
    $activity_where[] = "b.book_id = ?";
    $activity_params[] = $filter_book_id;
    $activity_types .= "i";
}

if (!empty($search_student_id)) {
    $activity_where[] = "u.student_id LIKE ?";
    $activity_params[] = "%" . $search_student_id . "%";
    $activity_types .= "s";
}

$activity_where_clause = !empty($activity_where) ? "WHERE " . implode(" AND ", $activity_where) : "";

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total 
              FROM quiz_attempts qa
              INNER JOIN users u ON qa.user_id = u.user_id
              INNER JOIN books b ON qa.book_id = b.book_id
              $activity_where_clause";

if (!empty($activity_params)) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($activity_types, ...$activity_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
} else {
    $count_result = $conn->query($count_sql);
}
$total_activities = $count_result->fetch_assoc()['total'];
$total_activity_pages = ceil($total_activities / $activity_per_page);

// Recent quiz activity with filters and pagination
$sql = "SELECT 
            u.student_id,
            b.title as book_title,
            b.book_id,
            qa.correct_answers,
            qa.total_questions,
            qa.score_percentage,
            DATE_FORMAT(qa.attempt_date, '%b %d, %Y') as formatted_date,
            DATE_FORMAT(qa.attempt_date, '%h:%i %p') as formatted_time
        FROM quiz_attempts qa
        INNER JOIN users u ON qa.user_id = u.user_id
        INNER JOIN books b ON qa.book_id = b.book_id
        $activity_where_clause
        ORDER BY qa.attempt_date DESC
        LIMIT $activity_per_page OFFSET $activity_offset";

if (!empty($activity_params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($activity_types, ...$activity_params);
    $stmt->execute();
    $recent_activity_result = $stmt->get_result();
} else {
    $recent_activity_result = $conn->query($sql);
}

// Store activity in array
$recent_activity = [];
while ($row = $recent_activity_result->fetch_assoc()) {
    $recent_activity[] = $row;
}

// Get all books for filter dropdown
$sql = "SELECT book_id, title FROM books ORDER BY title ASC";
$books_for_filter = $conn->query($sql);

// Helper function to build pagination URL
function buildPaginationUrl($params, $key, $value) {
    $params[$key] = $value;
    return '?' . http_build_query($params);
}

$current_params = $_GET;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librarian Dashboard - Library Hub</title>
    <link rel="icon" href="../images/library_hub_logo.png" type="image/png">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Vendor libraries (local for offline use) -->
    <script src="vendor/chart.umd.min.js"></script>
    <script src="vendor/jspdf.umd.min.js"></script>
    <script src="vendor/html2canvas.min.js"></script>
    <link href="vendor/datatables.min.css" rel="stylesheet">
    <link href="vendor/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* Add gradient variables and use as background */
        :root {
            /* DepEd Color Scheme */
            --deped-blue: #1a4480;
            --deped-blue-dark: #0d2240;
            --deped-red: #c41230;
            --deped-red-dark: #8b0a1e;
            --main-gradient: linear-gradient(135deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
            --main-gradient-overlay: linear-gradient(135deg, rgba(26,68,128,0.40) 0%, rgba(13,34,64,0.32) 45%, rgba(196,18,48,0.30) 100%);
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

        /* Boot Animation Styles */
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

        .container {
            max-width: 100%;
            padding: 0;
        }

        /* Section visibility */
        .section-hidden {
            display: none !important;
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

        .btn-logout {
            background: rgba(220, 53, 69, 0.9);
            color: white;
            padding: 10px 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-logout:hover {
            background: rgba(200, 35, 51, 0.9);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.4);
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .nav-links a {
            color: #667eea;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: all 0.3s;
            margin: 0 5px;
        }

        .nav-links a:hover {
            background: #667eea;
            color: white;
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

        /* Charts Section - NEW */
        .charts-section {
            margin: 30px 0;
        }

        .charts-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
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

        /* Wide chart card spanning full width */
        .chart-card-wide {
            grid-column: 1 / -1;
        }

        .chart-card-wide .chart-container {
            height: 350px;
        }

        /* Report Generation Section */
        .report-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 25px;
            margin: 30px 0;
            border: 2px solid #e0e0e0;
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .report-header h3 {
            color: #333;
            font-size: 1.3em;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Report Type Selector */
        .report-type-selector {
            margin-bottom: 25px;
        }

        .report-type-selector h4,
        .report-grade-filter h4,
        .report-content-options h4 {
            color: #333;
            font-size: 1em;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .report-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }

        .report-type-card {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .report-type-card:hover {
            border-color: var(--deped-blue);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .report-type-card.active {
            border-color: var(--deped-blue);
            background: linear-gradient(135deg, rgba(26,68,128,0.05) 0%, rgba(26,68,128,0.1) 100%);
        }

        .report-type-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .report-type-label {
            font-weight: 700;
            color: #333;
            font-size: 1em;
        }

        .report-type-desc {
            font-size: 0.85em;
            color: #666;
            margin-top: 5px;
        }

        /* Grade Level Filter */
        .report-grade-filter {
            background: rgba(255,255,255,0.7);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border: 1px solid #e0e0e0;
        }

        .grade-level-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
        }

        .grade-checkbox {
            display: block;
            cursor: pointer;
        }

        .grade-checkbox input {
            display: none;
        }

        .grade-box {
            display: block;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 15px;
            text-align: center;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
        }

        .grade-box.all {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .grade-checkbox input:checked + .grade-box {
            border-color: var(--deped-blue);
            background: var(--deped-blue);
            color: white;
        }

        .grade-checkbox input:checked + .grade-box.all {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        /* Content Toggle Grid */
        .report-content-options {
            margin-bottom: 25px;
        }

        .content-toggle-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }

        .content-toggle {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }

        .content-toggle:hover {
            border-color: var(--deped-blue);
            transform: translateY(-2px);
        }

        .content-toggle.active {
            border-color: var(--deped-blue);
            background: linear-gradient(135deg, rgba(26,68,128,0.05) 0%, rgba(26,68,128,0.1) 100%);
        }

        .content-toggle input {
            display: none;
        }

        .toggle-icon {
            font-size: 1.8em;
            margin-bottom: 8px;
        }

        .toggle-label {
            font-weight: 600;
            color: #333;
            font-size: 0.9em;
        }

        .report-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
        }

        .btn-export {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-export:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }

        .btn-export:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-preview {
            background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%);
        }

        .btn-preview:hover {
            box-shadow: 0 8px 25px rgba(23, 162, 184, 0.4);
        }

        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
        }

        .pagination a {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .pagination a:hover {
            background: #667eea;
            color: white;
        }

        .pagination span.current {
            background: var(--main-gradient);
            color: white;
            border: 2px solid transparent;
        }

        .pagination span.disabled {
            background: #e9ecef;
            color: #999;
            border: 2px solid #e9ecef;
            cursor: not-allowed;
        }

        .pagination .page-info {
            color: #666;
            font-size: 14px;
            padding: 10px 15px;
        }

        /* Activity Cards Design */
        .activity-section {
            margin-top: 30px;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .activity-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
            font-size: 1.4em;
        }

        .activity-count {
            background: #667eea;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .filter-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }

        .filter-bar select,
        .filter-bar input[type="text"] {
            padding: 12px 16px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 14px;
            min-width: 200px;
            background: white;
            transition: border-color 0.3s;
        }

        .filter-bar select:focus,
        .filter-bar input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }

        .filter-bar .btn {
            padding: 12px 24px;
        }

        .filter-bar .btn-clear {
            background: #6c757d;
            text-decoration: none;
        }

        .activity-grid {
            display: grid;
            gap: 15px;
        }

        .activity-item {
            background: white;
            border-radius: 15px;
            padding: 20px 25px;
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            align-items: center;
            gap: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s, box-shadow 0.3s;
            border-left: 4px solid #667eea;
        }

        .activity-item:hover {
            transform: translateX(5px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
        }

        .activity-item.excellent { border-left-color: #28a745; }
        .activity-item.good { border-left-color: #17a2b8; }
        .activity-item.average { border-left-color: #ffc107; }
        .activity-item.poor { border-left-color: #dc3545; }

        .activity-date {
            text-align: center;
            min-width: 80px;
        }

        .activity-date .day {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            line-height: 1;
        }

        .activity-date .month-year {
            font-size: 12px;
            color: #999;
            margin-top: 3px;
        }

        .activity-date .time {
            font-size: 11px;
            color: #667eea;
            margin-top: 5px;
            font-weight: 600;
        }

        .activity-details {
            flex: 1;
        }

        .activity-details .book-title {
            font-weight: 700;
            color: #333;
            font-size: 1.1em;
            margin-bottom: 5px;
        }

        .activity-details .student-id {
            color: #666;
            font-size: 13px;
        }

        .activity-details .student-id strong {
            color: #667eea;
        }

        .activity-score {
            text-align: center;
        }

        .activity-score .score-circle {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
        }

        .activity-score .score-circle.excellent { background: linear-gradient(135deg, #28a745, #20c997); }
        .activity-score .score-circle.good { background: linear-gradient(135deg, #17a2b8, #6f42c1); }
        .activity-score .score-circle.average { background: linear-gradient(135deg, #ffc107, #fd7e14); }
        .activity-score .score-circle.poor { background: linear-gradient(135deg, #dc3545, #c82333); }

        .activity-score .score-circle .percentage {
            font-size: 16px;
            line-height: 1;
        }

        .activity-score .score-circle .fraction {
            font-size: 10px;
            opacity: 0.9;
        }

        .activity-status {
            min-width: 120px;
            text-align: right;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-badge.excellent {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.good {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-badge.average {
            background: #fff3cd;
            color: #856404;
        }

        .status-badge.poor {
            background: #f8d7da;
            color: #721c24;
        }

        .empty-activity {
            text-align: center;
            padding: 60px 20px;
            background: #f8f9fa;
            border-radius: 15px;
            color: #999;
        }

        .empty-activity .icon {
            font-size: 60px;
            margin-bottom: 15px;
        }

        /* Table improvements */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table td {
            padding: 15px 12px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        .table tbody tr {
            transition: background 0.3s;
        }

        .table tbody tr:hover {
            background: #f8f9ff;
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Accordion Styles - RESTORED */
        .accordion-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            transition: all 0.3s;
        }

        .accordion-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .accordion-header h3 {
            margin: 0;
            font-size: 1.2em;
        }

        .accordion-icon {
            font-size: 1.5em;
            transition: transform 0.3s;
        }

        .accordion-icon.active {
            transform: rotate(180deg);
        }

        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .accordion-content.active {
            max-height: 1500px;
            transition: max-height 0.5s ease-in;
        }

        .accordion-body {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: inherit;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-hint {
            display: block;
            font-size: 11px;
            color: #888;
            margin-top: 4px;
        }

        .form-group input:invalid:not(:placeholder-shown) {
            border-color: #dc3545;
        }

        .form-group input:valid:not(:placeholder-shown) {
            border-color: #28a745;
        }

        .form-group select {
            cursor: pointer;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        /* Button Styles - RESTORED */
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
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-small {
            padding: 8px 15px;
            font-size: 14px;
        }

        .btn-danger {
            background: #dc3545 !important;
        }

        .btn-danger:hover {
            background: #c82333 !important;
        }

        /* Message Styles */
        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .message.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        /* Status badges for inventory */
        .status-available {
            background: #d4edda;
            color: #155724;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-unavailable {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        /* Modal Styles - RESTORED */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
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
            max-width: 600px;
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

        /* Export Progress Overlay */
        .export-overlay {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            align-items: center;
            justify-content: center;
        }

        .export-overlay.active {
            display: flex;
        }

        /* Report Preview Modal */
        .report-preview-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            align-items: center;
            justify-content: center;
        }

        .report-preview-modal.active {
            display: flex;
        }

        .report-preview-content {
            background: white;
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
        }

        .report-preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .report-preview-header h3 {
            margin: 0;
            font-size: 1.3em;
        }

        .report-preview-header .close-modal {
            color: white;
            font-size: 28px;
        }

        .report-preview-header .close-modal:hover {
            color: rgba(255, 255, 255, 0.8);
        }

        .report-preview-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
            background: #f8f9fa;
        }

        /* Responsive Styles */
        @media (max-width: 992px) {
            .navbar {
                flex-wrap: wrap;
                    gap: 10px;
                    padding: 10px 15px;
                    box-shadow: 0 12px 40px rgba(0,0,0,0.45);
                    transform: translateZ(0);
                    will-change: box-shadow, transform;
            }

            .nav-center {
                order: 3;
                width: 100%;
                justify-content: center;
                margin-top: 10px;
            }

            .nav-btn {
                padding: 8px 15px;
                font-size: 13px;
            }

            .nav-brand-text {
                font-size: 1.1em;
            }
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .chart-card {
                margin-bottom: 20px;
            }

            .report-type-grid {
                grid-template-columns: 1fr;
            }

            .grade-level-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .content-toggle-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .report-preview-content {
                padding: 20px;
            }

            .report-preview-header h3 {
                font-size: 1.2em;
            }

            .report-preview-body {
                font-size: 12px;
            }

            .dataTables_wrapper .dataTables_filter input {
                min-width: 150px;
            }
        }

        /* DataTables Styling */
        .dataTables_wrapper {
            padding: 20px 0;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 15px;
        }

        .dataTables_wrapper .dataTables_length label,
        .dataTables_wrapper .dataTables_filter label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            color: #333;
        }

        .dataTables_wrapper .dataTables_filter input {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 10px 15px;
            margin-left: 8px;
            min-width: 250px;
            font-size: 14px;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--deped-blue);
            outline: none;
            box-shadow: 0 0 0 3px rgba(26,68,128,0.1);
        }

        .dataTables_wrapper .dataTables_length select {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 14px;
            cursor: pointer;
            background: white;
            min-width: 80px;
        }

        .dataTables_wrapper .dataTables_length select:focus {
            border-color: var(--deped-blue);
            outline: none;
        }

        .dataTables_wrapper .dataTables_info {
            color: #666;
            font-size: 14px;
            padding: 10px 0;
        }

        .dataTables_wrapper .dataTables_paginate {
            margin-top: 15px;
            padding: 10px 0;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 8px 15px;
            margin: 0 3px;
            border-radius: 6px;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--deped-blue);
            color: white !important;
            border-color: var(--deped-blue);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--deped-blue);
            color: white !important;
            border-color: var(--deped-blue);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
    <!-- Boot Loader -->
    <div id="bootLoader">
        <div class="boot-logo">
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="boot-icon"><img src="../images/library_hub_logo.png" alt="Library Hub" class="boot-logo"></div>
        </div>
        <div class="boot-text">Library Hub</div>
        <div class="boot-subtitle">Loading Librarian Dashboard<span class="loading-dots"></span></div>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <a href="index.php" class="sidebar-brand">
            <div class="sidebar-brand-icon"><img src="../images/library_hub_logo.png" alt="Library Hub" class="sidebar-logo"></div>
            <span class="sidebar-brand-text">Library Hub</span>
        </a>

        <ul class="sidebar-menu">
            <li><a class="active" data-section="dashboard" onclick="showSection('dashboard')"><i class="fas fa-chart-bar"></i> <span>Dashboard</span></a></li>
            <li><a data-section="books" onclick="showSection('books')"><i class="fas fa-book"></i> <span>Book Management</span></a></li>
            <li><a data-section="reports" onclick="showSection('dashboard'); document.querySelector('.report-section').scrollIntoView({behavior: 'smooth'});"><i class="fas fa-file-pdf"></i> <span>Reports</span></a></li>
        </ul>

        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </a>
    </aside>

    <!-- Main Content -->
    <div id="mainContent">
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1>📊 Librarian Dashboard</h1>
                <div class="header-actions">
                    <div class="user-profile">
                        <div class="user-avatar">📚</div>
                        <div class="user-info-header">
                            <h4><?php echo escape_output($_SESSION['full_name'] ?? ($_SESSION['email'] ?? 'Librarian')); ?></h4>
                            <span><?php echo ucfirst(escape_output($_SESSION['user_type'] ?? 'librarian')); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="message success"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="message error"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- ========== DASHBOARD ANALYTICS SECTION ========== -->
            <div id="dashboardSection">
            
            <!-- Dashboard Statistics -->
            <div class="card">
                <h2>📊 Dashboard Overview</h2>
                <div class="dashboard">
                    <div class="dashboard-card">
                        <h3>📚 Total Books</h3>
                        <div class="stats">
                            <span class="stat-number"><?php echo $stats['total_books']; ?></span>
                        </div>
                        <p>Books in library</p>
                    </div>
                    <div class="dashboard-card">
                        <h3>✅ Available Books</h3>
                        <div class="stats">
                            <span class="stat-number" style="color: #28a745;"><?php echo $stats['available_books']; ?></span>
                        </div>
                        <p>Ready for borrowing</p>
                    </div>
                    <div class="dashboard-card">
                        <h3>❌ Unavailable</h3>
                        <div class="stats">
                            <span class="stat-number" style="color: #dc3545;"><?php echo $stats['unavailable_books']; ?></span>
                        </div>
                        <p>Currently unavailable</p>
                    </div>
                    <div class="dashboard-card">
                        <h3>📝 Quiz Attempts</h3>
                        <div class="stats">
                            <span class="stat-number" style="color: #ffc107;"><?php echo $stats['total_attempts']; ?></span>
                        </div>
                        <p>Total attempts</p>
                    </div>
                </div>
            </div>
            <!-- Charts Section -->
            <div class="card charts-section">
                <div class="charts-header">
                    <h3>📈 Analytics & Charts</h3>
                </div>
                <div class="charts-grid">
                    <div class="chart-card">
                        <h4>📊 Quiz Attempts by Book (Top 10)</h4>
                        <div class="chart-container">
                            <canvas id="attemptsBarChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h4>🎯 Score Distribution</h4>
                        <div class="chart-container pie-chart">
                            <canvas id="scoresPieChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h4>📅 Monthly Quiz Activity</h4>
                        <div class="chart-container">
                            <canvas id="monthlyBarChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card">
                        <h4>📚 Books by Genre</h4>
                        <div class="chart-container pie-chart">
                            <canvas id="genrePieChart"></canvas>
                        </div>
                    </div>
                    <div class="chart-card chart-card-wide">
                        <h4>📚 Performance by Grade Level</h4>
                        <div class="chart-container">
                            <canvas id="gradeLevelChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Generation Section -->
            <div class="report-section">
                <div class="report-header">
                    <h3>📄 Generate Report</h3>
                </div>
                
                <!-- Report Type Selection -->
                <div class="report-type-selector">
                    <h4>📋 Select Report Type</h4>
                    <div class="report-type-grid">
                        <div class="report-type-card active" data-type="full" onclick="selectReportType('full')">
                            <div class="report-type-icon">📊</div>
                            <div class="report-type-label">Full Report</div>
                            <div class="report-type-desc">Complete library analytics</div>
                        </div>
                        <div class="report-type-card" data-type="grade" onclick="selectReportType('grade')">
                            <div class="report-type-icon">🎓</div>
                            <div class="report-type-label">By Grade Level</div>
                            <div class="report-type-desc">Performance per grade</div>
                        </div>
                        <div class="report-type-card" data-type="book" onclick="selectReportType('book')">
                            <div class="report-type-icon">📚</div>
                            <div class="report-type-label">Book Performance</div>
                            <div class="report-type-desc">Individual book stats</div>
                        </div>
                    </div>
                </div>

                <!-- Grade Level Filter (shown when grade report selected) -->
                <div class="report-grade-filter" id="gradeFilterSection" style="display: none;">
                    <h4>🎓 Select Grade Levels</h4>
                    <div class="grade-level-grid">
                        <label class="grade-checkbox">
                            <input type="checkbox" id="gradeAll" checked onchange="toggleAllGrades()">
                            <span class="grade-box all">All Grades</span>
                        </label>
                        <label class="grade-checkbox">
                            <input type="checkbox" class="grade-check" value="1" checked>
                            <span class="grade-box">Grade 1</span>
                        </label>
                        <label class="grade-checkbox">
                            <input type="checkbox" class="grade-check" value="2" checked>
                            <span class="grade-box">Grade 2</span>
                        </label>
                        <label class="grade-checkbox">
                            <input type="checkbox" class="grade-check" value="3" checked>
                            <span class="grade-box">Grade 3</span>
                        </label>
                        <label class="grade-checkbox">
                            <input type="checkbox" class="grade-check" value="4" checked>
                            <span class="grade-box">Grade 4</span>
                        </label>
                        <label class="grade-checkbox">
                            <input type="checkbox" class="grade-check" value="5" checked>
                            <span class="grade-box">Grade 5</span>
                        </label>
                        <label class="grade-checkbox">
                            <input type="checkbox" class="grade-check" value="6" checked>
                            <span class="grade-box">Grade 6</span>
                        </label>
                    </div>
                </div>

                <!-- Report Content Options -->
                <div class="report-content-options">
                    <h4>📝 Include in Report</h4>
                    <div class="content-toggle-grid">
                        <label class="content-toggle active">
                            <input type="checkbox" id="includeStats" checked>
                            <span class="toggle-icon">📊</span>
                            <span class="toggle-label">Statistics</span>
                        </label>
                        <label class="content-toggle active">
                            <input type="checkbox" id="includeCharts" checked>
                            <span class="toggle-icon">📈</span>
                            <span class="toggle-label">Charts</span>
                        </label>
                        <label class="content-toggle active">
                            <input type="checkbox" id="includePerformance" checked>
                            <span class="toggle-icon">🏆</span>
                            <span class="toggle-label">Performance</span>
                        </label>
                        <label class="content-toggle active">
                            <input type="checkbox" id="includeInventory" checked>
                            <span class="toggle-icon">📚</span>
                            <span class="toggle-label">Inventory</span>
                        </label>
                        <label class="content-toggle active">
                            <input type="checkbox" id="includeActivity" checked>
                            <span class="toggle-icon">📝</span>
                            <span class="toggle-label">Activity</span>
                        </label>
                    </div>
                </div>

                <div class="report-actions">
                    <button type="button" class="btn-export btn-preview" onclick="previewReport()">👁️ Preview Report</button>
                    <button type="button" class="btn-export" onclick="generatePDF()">📥 Export as PDF</button>
                </div>
            </div>

            </div><!-- END DASHBOARD ANALYTICS SECTION -->

            <!-- ========== BOOK MANAGEMENT SECTION ========== -->
            <div id="booksSection" class="section-hidden">

            <!-- Add New Book Accordion -->
            <div class="card">
                <div class="accordion-header" onclick="toggleAccordion('addBookAccordion', this)">
                    <h3>➕ Add New Book</h3>
                    <span class="accordion-icon">▼</span>
                </div>
                <div class="accordion-content" id="addBookAccordion">
                    <div class="accordion-body">
                        <form method="POST" action="" id="addBookForm" onsubmit="return validateAddBookForm()">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="title">Book Title *</label>
                                    <input type="text" name="title" id="title" required 
                                           pattern=".{2,150}" title="Title must be 2-150 characters"
                                           placeholder="Enter book title">
                                    <small class="form-hint">2-150 characters</small>
                                </div>
                                <div class="form-group">
                                    <label for="author">Author *</label>
                                    <input type="text" name="author" id="author" required
                                           pattern="[A-Za-z\s.'-]{2,100}" title="Author name: letters, spaces, periods, hyphens only"
                                           placeholder="Enter author name">
                                    <small class="form-hint">Letters, spaces, periods, hyphens allowed</small>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="genre">Genre</label>
                                    <select name="genre" id="genre">
                                        <option value="">-- Select Genre --</option>
                                        <option value="Fiction">Fiction</option>
                                        <option value="Non-Fiction">Non-Fiction</option>
                                        <option value="Fantasy">Fantasy</option>
                                        <option value="Adventure">Adventure</option>
                                        <option value="Mystery">Mystery</option>
                                        <option value="Science Fiction">Science Fiction</option>
                                        <option value="Biography">Biography</option>
                                        <option value="History">History</option>
                                        <option value="Science">Science</option>
                                        <option value="Poetry">Poetry</option>
                                        <option value="Drama">Drama</option>
                                        <option value="Fairy Tales">Fairy Tales</option>
                                        <option value="Fables">Fables</option>
                                        <option value="Educational">Educational</option>
                                        <option value="Comics">Comics</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="recommended_grade">Recommended Grade Level</label>
                                    <select name="recommended_grade" id="recommended_grade">
                                        <option value="">-- Select Grade Level --</option>
                                        <option value="1">Grade 1</option>
                                        <option value="2">Grade 2</option>
                                        <option value="3">Grade 3</option>
                                        <option value="4">Grade 4</option>
                                        <option value="5">Grade 5</option>
                                        <option value="6">Grade 6</option>
                                        <option value="All">All Grades</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="total_pages">Total Pages</label>
                                    <input type="number" name="total_pages" id="total_pages" 
                                           min="1" max="9999" step="1"
                                           placeholder="Enter number of pages"
                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                    <small class="form-hint">1-9999 pages</small>
                                </div>
                                <div class="form-group">
                                    <label for="difficulty">Difficulty Level</label>
                                    <select name="difficulty" id="difficulty" required>
                                        <option value="beginner">Beginner</option>
                                        <option value="intermediate">Intermediate</option>
                                        <option value="advanced">Advanced</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="qr_code">QR Code Identifier *</label>
                                <input type="text" name="qr_code" id="qr_code" required 
                                       pattern="[A-Za-z0-9_-]{3,50}" title="QR Code: 3-50 alphanumeric characters, underscores, hyphens"
                                       placeholder="Enter unique QR code identifier (e.g., BOOK-001)">
                                <small class="form-hint">3-50 alphanumeric characters, underscores, hyphens allowed</small>
                            </div>
                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea name="description" id="description" rows="4" 
                                          maxlength="1000" placeholder="Enter book description (optional)"
                                          oninput="updateCharCount(this, 'descCharCount')"></textarea>
                                <small class="form-hint"><span id="descCharCount">0</span>/1000 characters</small>
                            </div>
                            <button type="submit" name="add_book" class="btn">➕ Add Book</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Book Inventory -->
            <div class="card">
                <h2>📚 Book Inventory</h2>
                <div class="table-container">
                    <table class="table" id="bookInventoryTable">
                        <thead>
                            <tr>
                                <th>QR Code</th>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Genre</th>
                                <th>Status</th>
                                <th>Times Read</th>
                                <th>Avg Score</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($book_inventory)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                                        No books found. Add your first book above!
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($book_inventory as $book): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($book['qr_code']); ?></code></td>
                                        <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($book['author']); ?></td>
                                        <td><?php echo htmlspecialchars($book['genre'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($book['is_available']): ?>
                                                <span class="status-available">Available</span>
                                            <?php else: ?>
                                                <span class="status-unavailable">Unavailable</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $book['times_read']; ?></td>
                                        <td><?php echo round($book['avg_score'], 1); ?>%</td>
                                        <td>
                                            <button type="button" class="btn btn-small" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($book)); ?>)">Edit</button>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this book?');">
                                                <input type="hidden" name="book_id" value="<?php echo $book['book_id']; ?>">
                                                <button type="submit" name="delete_book" class="btn btn-small btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- DataTables handles pagination, search, and show entries -->
            </div>

            <!-- Recent Activity Section -->
            <div class="card activity-section">
                <div class="activity-header">
                    <h3>📝 Recent Quiz Activity <span class="activity-count"><?php echo $total_activities; ?> total</span></h3>
                </div>

                <!-- Filter Bar -->
                <form method="GET" class="filter-bar">
                    <select name="filter_book">
                        <option value="">All Books</option>
                        <?php while ($book_option = $books_for_filter->fetch_assoc()): ?>
                            <option value="<?php echo $book_option['book_id']; ?>" <?php echo $filter_book_id == $book_option['book_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($book_option['title']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <input type="text" name="search_student" placeholder="Search Student ID..." value="<?php echo htmlspecialchars($search_student_id); ?>">
                    <button type="submit" class="btn btn-small">🔍 Filter</button>
                    <a href="librarian.php" class="btn btn-small btn-clear">Clear</a>
                </form>

                <!-- Activity Grid -->
                <div class="activity-grid">
                    <?php if (empty($recent_activity)): ?>
                        <div class="empty-activity">
                            <div class="icon">📭</div>
                            <h4>No activity found</h4>
                            <p>Quiz attempts will appear here when students complete quizzes.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_activity as $activity): 
                            $score = $activity['score_percentage'];
                            if ($score >= 90) {
                                $scoreClass = 'excellent';
                                $statusText = 'Excellent';
                            } elseif ($score >= 70) {
                                $scoreClass = 'good';
                                $statusText = 'Good';
                            } elseif ($score >= 50) {
                                $scoreClass = 'average';
                                $statusText = 'Average';
                            } else {
                                $scoreClass = 'poor';
                                $statusText = 'Needs Work';
                            }
                            
                            // Parse date for display
                            $dateParts = explode(' ', $activity['formatted_date']);
                            $day = isset($dateParts[1]) ? rtrim($dateParts[1], ',') : '';
                            $monthYear = isset($dateParts[0]) ? $dateParts[0] : '';
                            if (isset($dateParts[2])) $monthYear .= ' ' . $dateParts[2];
                        ?>
                            <div class="activity-item <?php echo $scoreClass; ?>">
                                <div class="activity-date">
                                    <div class="day"><?php echo $day; ?></div>
                                    <div class="month-year"><?php echo $monthYear; ?></div>
                                    <div class="time"><?php echo $activity['formatted_time']; ?></div>
                                </div>
                                <div class="activity-details">
                                    <div class="book-title"><?php echo htmlspecialchars($activity['book_title']); ?></div>
                                    <div class="student-id">Student: <strong><?php echo htmlspecialchars($activity['student_id']); ?></strong></div>
                                </div>
                                <div class="activity-score">
                                    <div class="score-circle <?php echo $scoreClass; ?>">
                                        <span class="percentage"><?php echo round($score); ?>%</span>
                                        <span class="fraction"><?php echo $activity['correct_answers']; ?>/<?php echo $activity['total_questions']; ?></span>
                                    </div>
                                </div>
                                <div class="activity-status">
                                    <span class="status-badge <?php echo $scoreClass; ?>"><?php echo $statusText; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Activity Pagination -->
                <?php if ($total_activity_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($activity_page > 1): ?>
                            <a href="<?php echo buildPaginationUrl($current_params, 'activity_page', 1); ?>">« First</a>
                            <a href="<?php echo buildPaginationUrl($current_params, 'activity_page', $activity_page - 1); ?>">‹ Prev</a>
                        <?php else: ?>
                            <span class="disabled">« First</span>
                            <span class="disabled">‹ Prev</span>
                        <?php endif; ?>

                        <span class="page-info">Page <?php echo $activity_page; ?> of <?php echo $total_activity_pages; ?></span>

                        <?php if ($activity_page < $total_activity_pages): ?>
                            <a href="<?php echo buildPaginationUrl($current_params, 'activity_page', $activity_page + 1); ?>">Next ›</a>
                            <a href="<?php echo buildPaginationUrl($current_params, 'activity_page', $total_activity_pages); ?>">Last »</a>
                        <?php else: ?>
                            <span class="disabled">Next ›</span>
                            <span class="disabled">Last »</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            </div><!-- END BOOK MANAGEMENT SECTION -->

        </div>
    </div>

    <!-- Edit Book Modal -->
    <div class="modal" id="editBookModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✏️ Edit Book</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="book_id" id="edit_book_id">
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_title">Book Title *</label>
                        <input type="text" name="title" id="edit_title" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_author">Author *</label>
                        <input type="text" name="author" id="edit_author" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_genre">Genre</label>
                        <select name="genre" id="edit_genre">
                            <option value="">-- Select Genre --</option>
                            <option value="Fiction">Fiction</option>
                            <option value="Non-Fiction">Non-Fiction</option>
                            <option value="Fantasy">Fantasy</option>
                            <option value="Adventure">Adventure</option>
                            <option value="Mystery">Mystery</option>
                            <option value="Science Fiction">Science Fiction</option>
                            <option value="Biography">Biography</option>
                            <option value="History">History</option>
                            <option value="Science">Science</option>
                            <option value="Poetry">Poetry</option>
                            <option value="Drama">Drama</option>
                            <option value="Fairy Tales">Fairy Tales</option>
                            <option value="Fables">Fables</option>
                            <option value="Educational">Educational</option>
                            <option value="Comics">Comics</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_recommended_grade">Recommended Grade Level</label>
                        <select name="recommended_grade" id="edit_recommended_grade">
                            <option value="">-- Select Grade Level --</option>
                            <option value="1">Grade 1</option>
                            <option value="2">Grade 2</option>
                            <option value="3">Grade 3</option>
                            <option value="4">Grade 4</option>
                            <option value="5">Grade 5</option>
                            <option value="6">Grade 6</option>
                            <option value="All">All Grades</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_total_pages">Total Pages</label>
                        <input type="number" name="total_pages" id="edit_total_pages" min="1" max="9999">
                    </div>
                    <div class="form-group">
                        <label for="edit_difficulty">Difficulty Level</label>
                        <select name="difficulty" id="edit_difficulty">
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_description">Description</label>
                    <textarea name="description" id="edit_description" rows="4"></textarea>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_available" id="edit_is_available"> Book is Available
                    </label>
                </div>
                <button type="submit" name="update_book" class="btn">💾 Update Book</button>
            </form>
        </div>
    </div>

    <!-- Export Progress Overlay -->
    <div class="export-overlay" id="exportOverlay">
        <div class="export-progress">
            <div class="spinner"></div>
            <h4>Generating PDF Report...</h4>
            <p id="exportStatus">Preparing data...</p>
        </div>
    </div>

    <!-- Report Preview Modal -->
    <div class="report-preview-modal" id="reportPreviewModal">
        <div class="report-preview-content">
            <div class="report-preview-header">
                <h3>📄 Report Preview</h3>
                <button class="close-modal" onclick="closePreviewModal()">&times;</button>
            </div>
            <div class="report-preview-body" id="reportPreviewBody">
                <!-- Preview content will be inserted here -->
            </div>
            <div style="padding: 20px; text-align: right; border-top: 1px solid #eee;">
                <button type="button" class="btn" style="background: #6c757d; margin-right: 10px;" onclick="closePreviewModal()">Close</button>
                <button type="button" class="btn-export" onclick="closePreviewModal(); generatePDF();">📥 Export as PDF</button>
            </div>
        </div>
    </div>

    <script>
        // ===== Chart.js Initialization =====
        
        // Chart Data from PHP
        const attemptsData = <?php echo json_encode($chart_attempts_data); ?>;
        const scoresData = <?php echo json_encode($chart_scores_data); ?>;
        const genreData = <?php echo json_encode($chart_genre_data); ?>;
        const monthlyData = <?php echo json_encode($chart_monthly_data); ?>;
        const gradeData = <?php echo json_encode($chart_grade_data); ?>;
        
        // Statistics for PDF
        const statsData = {
            totalBooks: <?php echo $stats['total_books']; ?>,
            availableBooks: <?php echo $stats['available_books']; ?>,
            unavailableBooks: <?php echo $stats['unavailable_books']; ?>,
            totalAttempts: <?php echo $stats['total_attempts']; ?>
        };

        // ===== Report Type & Grade Level Functions =====
        let selectedReportType = 'full';
        
        function selectReportType(type) {
            selectedReportType = type;
            
            // Update card styling
            document.querySelectorAll('.report-type-card').forEach(card => {
                card.classList.remove('active');
            });
            document.querySelector(`.report-type-card[data-type="${type}"]`).classList.add('active');
            
            // Show/hide grade filter
            const gradeFilter = document.getElementById('gradeFilterSection');
            if (type === 'grade') {
                gradeFilter.style.display = 'block';
            } else {
                gradeFilter.style.display = 'none';
            }
        }
        
        function toggleAllGrades() {
            const allChecked = document.getElementById('gradeAll').checked;
            document.querySelectorAll('.grade-check').forEach(cb => {
                cb.checked = allChecked;
            });
        }
        
        function getSelectedGrades() {
            const grades = [];
            document.querySelectorAll('.grade-check:checked').forEach(cb => {
                grades.push(cb.value);
            });
            return grades;
        }
        
        // Toggle content options
        document.querySelectorAll('.content-toggle input').forEach(input => {
            input.addEventListener('change', function() {
                if (this.checked) {
                    this.closest('.content-toggle').classList.add('active');
                } else {
                    this.closest('.content-toggle').classList.remove('active');
                }
            });
        });

        // ===== Section Toggle Functions =====
        function showSection(section) {
            const dashboardSection = document.getElementById('dashboardSection');
            const booksSection = document.getElementById('booksSection');
            
            // Update sidebar active states
            document.querySelectorAll('.sidebar-menu a').forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('data-section') === section) {
                    link.classList.add('active');
                }
            });
            
            // Update page title
            const pageTitle = document.querySelector('.page-header h1');
            if (section === 'dashboard') {
                dashboardSection.classList.remove('section-hidden');
                booksSection.classList.add('section-hidden');
                pageTitle.textContent = '📊 Dashboard & Analytics';
            } else if (section === 'books') {
                dashboardSection.classList.add('section-hidden');
                booksSection.classList.remove('section-hidden');
                pageTitle.textContent = '📚 Book Management';
            }
            
            // Scroll to top of container
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ===== Add Book Form Validation =====
        function validateAddBookForm() {
            const title = document.getElementById('title').value.trim();
            const author = document.getElementById('author').value.trim();
            const qrCode = document.getElementById('qr_code').value.trim();
            const totalPages = document.getElementById('total_pages').value;
            
            // Title validation
            if (title.length < 2 || title.length > 150) {
                alert('❌ Book title must be between 2 and 150 characters.');
                document.getElementById('title').focus();
                return false;
            }
            
            // Author validation
            const authorPattern = /^[A-Za-z\s.'-]{2,100}$/;
            if (!authorPattern.test(author)) {
                alert('❌ Author name must be 2-100 characters and contain only letters, spaces, periods, and hyphens.');
                document.getElementById('author').focus();
                return false;
            }
            
            // QR Code validation
            const qrPattern = /^[A-Za-z0-9_-]{3,50}$/;
            if (!qrPattern.test(qrCode)) {
                alert('❌ QR Code must be 3-50 alphanumeric characters, underscores, or hyphens.');
                document.getElementById('qr_code').focus();
                return false;
            }
            
            // Total pages validation
            if (totalPages && (totalPages < 1 || totalPages > 9999)) {
                alert('❌ Total pages must be between 1 and 9999.');
                document.getElementById('total_pages').focus();
                return false;
            }
            
            return true;
        }
        
        function updateCharCount(textarea, counterId) {
            const count = textarea.value.length;
            document.getElementById(counterId).textContent = count;
        }

        // ===== Accordion Functions =====
        function toggleAccordion(id, headerElement) {
            const content = document.getElementById(id);
            const icon = headerElement.querySelector('.accordion-icon');
            
            if (content.classList.contains('active')) {
                content.classList.remove('active');
                icon.classList.remove('active');
            } else {
                content.classList.add('active');
                icon.classList.add('active');
            }
        }

        // ===== Edit Modal Functions =====
               function openEditModal(book) {
            document.getElementById('edit_book_id').value = book.book_id;
            document.getElementById('edit_title').value = book.title || '';
            document.getElementById('edit_author').value = book.author || '';
            document.getElementById('edit_genre').value = book.genre || '';
            document.getElementById('edit_recommended_grade').value = book.recommended_grade_level || '';
            document.getElementById('edit_total_pages').value = book.total_pages > 0 ? book.total_pages : '';
            document.getElementById('edit_difficulty').value = book.difficulty_level || 'beginner';
            document.getElementById('edit_description').value = book.description || '';
            document.getElementById('edit_is_available').checked = book.is_available == 1;
            
            document.getElementById('editBookModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editBookModal').classList.remove('active');
        }

        // Close edit modal when clicking outside
        document.getElementById('editBookModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
                closePreviewModal();
            }
        });

        // Color Palettes
        const barColors = [
            'rgba(102, 126, 234, 0.8)',
            'rgba(118, 75, 162, 0.8)',
            'rgba(255, 122, 61, 0.8)',
            'rgba(40, 167, 69, 0.8)',
            'rgba(23, 162, 184, 0.8)',
            'rgba(255, 193, 7, 0.8)',
            'rgba(220, 53, 69, 0.8)',
            'rgba(111, 66, 193, 0.8)',
            'rgba(32, 201, 151, 0.8)',
            'rgba(253, 126, 20, 0.8)'
        ];

        const pieColors = [
            'rgba(40, 167, 69, 0.85)',
            'rgba(23, 162, 184, 0.85)',
            'rgba(255, 193, 7, 0.85)',
            'rgba(220, 53, 69, 0.85)'
        ];

        const genreColors = [
            'rgba(102, 126, 234, 0.85)',
            'rgba(255, 122, 61, 0.85)',
            'rgba(40, 167, 69, 0.85)',
            'rgba(255, 193, 7, 0.85)',
            'rgba(220, 53, 69, 0.85)',
            'rgba(111, 66, 193, 0.85)',
            'rgba(23, 162, 184, 0.85)',
            'rgba(253, 126, 20, 0.85)'
        ];

        // Initialize Charts after page load
        let attemptsChart, scoresChart, monthlyChart, genreChart, gradeLevelChart;

        function initializeCharts() {
            // Bar Chart: Quiz Attempts per Book
            const attemptsCtx = document.getElementById('attemptsBarChart');
            attemptsChart = new Chart(attemptsCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: attemptsData.map(item => item.title.length > 20 ? item.title.substring(0, 20) + '...' : item.title),
                    datasets: [{
                        label: 'Quiz Attempts',
                        data: attemptsData.map(item => item.attempts),
                        backgroundColor: barColors,
                        borderColor: barColors.map(c => c.replace('0.8', '1')),
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                afterLabel: function(context) {
                                    const dataIndex = context.dataIndex;
                                    return 'Avg Score: ' + (attemptsData[dataIndex].avg_score || 0) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            },
                            title: {
                                display: true,
                                text: 'Number of Attempts'
                            }
                        },
                        x: {
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });

            // Pie Chart: Score Distribution
            const scoresCtx = document.getElementById('scoresPieChart');
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
                                font: {
                                    size: 11
                                }
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

            // Bar Chart: Monthly Activity
            const monthlyCtx = document.getElementById('monthlyBarChart');
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
                    scales: {
                        y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Attempts' } },
                        y1: { min: 0, max: 100, position: 'right', title: { display: true, text: 'Avg Score (%)' }, grid: { drawOnChartArea: false } }
                    }
                }
            });

            // Pie Chart: Genre Distribution
            const genreCtx = document.getElementById('genrePieChart');
            genreChart = new Chart(genreCtx.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: genreData.map(item => item.genre),
                    datasets: [{
                        data: genreData.map(item => item.count),
                        backgroundColor: genreColors,
                        borderColor: 'white',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 12,
                                usePointStyle: true,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });

            // Bar Chart: Grade Level Performance
            const gradeCtx = document.getElementById('gradeLevelChart');
            gradeLevelChart = new Chart(gradeCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: gradeData.map(item => 'Grade ' + item.grade_level),
                    datasets: [
                        {
                            label: 'Total Books',
                            data: gradeData.map(item => item.total_books),
                            backgroundColor: 'rgba(102, 126, 234, 0.85)',
                            borderColor: 'rgba(102, 126, 234, 1)',
                            borderWidth: 2,
                            borderRadius: 6,
                            order: 2
                        },
                        {
                            label: 'Books with Quiz Attempts',
                            data: gradeData.map(item => item.books_with_attempts),
                            backgroundColor: 'rgba(40, 167, 69, 0.85)',
                            borderColor: 'rgba(40, 167, 69, 1)',
                            borderWidth: 2,
                            borderRadius: 6,
                            order: 3
                        },
                        {
                            label: 'Total Quiz Attempts',
                            data: gradeData.map(item => item.total_attempts),
                            backgroundColor: 'rgba(255, 193, 7, 0.85)',
                            borderColor: 'rgba(255, 193, 7, 1)',
                            borderWidth: 2,
                            borderRadius: 6,
                            order: 4
                        },
                        {
                            label: 'Passing Attempts',
                            data: gradeData.map(item => item.passing_attempts),
                            backgroundColor: 'rgba(23, 162, 184, 0.85)',
                            borderColor: 'rgba(23, 162, 184, 1)',
                            borderWidth: 2,
                            borderRadius: 6,
                            order: 5
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
                                font: {
                                    size: 11
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                afterBody: function(context) {
                                    const dataIndex = context[0].dataIndex;
                                    const item = gradeData[dataIndex];
                                    return [
                                        '',
                                        'Avg Score: ' + (item.avg_score || 0) + '%'
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Count'
                            },
                            ticks: {
                                stepSize: 1
                            }
                        },
                        y1: {
                            min: 0,
                            max: 100,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Pass Rate (%)'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: 11
                                }
                            }
                        }
                    }
                }
            });
        }

        // ===== PDF Generation Functions =====
        
        // Helper function for high-resolution chart export
        function getHighResChartImage(canvas, scale = 2) {
            // Create a temporary canvas with higher resolution
            const tempCanvas = document.createElement('canvas');
            const ctx = tempCanvas.getContext('2d');
            
            // Set dimensions to scaled size
            tempCanvas.width = canvas.width * scale;
            tempCanvas.height = canvas.height * scale;
            
            // Scale and draw
            ctx.scale(scale, scale);
            ctx.fillStyle = 'white';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(canvas, 0, 0);
            
            return tempCanvas.toDataURL('image/png', 1.0);
        }
        
        async function generatePDF() {
            const { jsPDF } = window.jspdf;
            const overlay = document.getElementById('exportOverlay');
            const statusEl = document.getElementById('exportStatus');
            
            overlay.classList.add('active');
            
            try {
                const pdf = new jsPDF('p', 'mm', 'a4');
                const pageWidth = pdf.internal.pageSize.getWidth();
                const pageHeight = pdf.internal.pageSize.getHeight();
                const margin = 15;
                let yPos = margin;
                
                // Header
                statusEl.textContent = 'Creating header...';
                pdf.setFillColor(102, 126, 234);
                pdf.rect(0, 0, pageWidth, 35, 'F');
                
                pdf.setTextColor(255, 255, 255);
                pdf.setFontSize(22);
                pdf.setFont('helvetica', 'bold');
                pdf.text('Library Hub - Librarian Report', pageWidth / 2, 18, { align: 'center' });
                
                pdf.setFontSize(10);
                pdf.setFont('helvetica', 'normal');
                const currentDate = new Date().toLocaleDateString('en-US', { 
                    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });
                pdf.text('Generated: ' + currentDate, pageWidth / 2, 28, { align: 'center' });
                
                // Add selected grade levels to header if grade report type
                const selectedGrades = getSelectedGrades();
                if (selectedReportType === 'grade' && selectedGrades.length > 0 && selectedGrades.length < 6) {
                    pdf.setFontSize(9);
                    pdf.text('Grade Levels: ' + selectedGrades.map(g => 'Grade ' + g).join(', '), pageWidth / 2, 33, { align: 'center' });
                }
                
                yPos = 45;
                
                // Statistics Section
                if (document.getElementById('includeStats').checked) {
                    statusEl.textContent = 'Adding statistics...';
                    
                    pdf.setTextColor(51, 51, 51);
                    pdf.setFontSize(14);
                    pdf.setFont('helvetica', 'bold');
                    pdf.text('📊 Statistics Overview', margin, yPos);
                    yPos += 10;
                    
                    // Stats boxes
                    const boxWidth = (pageWidth - margin * 2 - 15) / 4;
                    const statsItems = [
                        { label: 'Total Books', value: statsData.totalBooks, color: [102, 126, 234] },
                        { label: 'Available', value: statsData.availableBooks, color: [40, 167, 69] },
                        { label: 'Unavailable', value: statsData.unavailableBooks, color: [220, 53, 69] },
                        { label: 'Quiz Attempts', value: statsData.totalAttempts, color: [255, 193, 7] }
                    ];
                    
                    statsItems.forEach((item, index) => {
                        const xPos = margin + (boxWidth + 5) * index;
                        pdf.setFillColor(...item.color);
                        pdf.roundedRect(xPos, yPos, boxWidth, 25, 3, 3, 'F');
                        
                        pdf.setTextColor(255, 255, 255);
                        pdf.setFontSize(16);
                        pdf.setFont('helvetica', 'bold');
                        pdf.text(String(item.value), xPos + boxWidth / 2, yPos + 12, { align: 'center' });
                        
                        pdf.setFontSize(8);
                        pdf.setFont('helvetica', 'normal');
                        pdf.text(item.label, xPos + boxWidth / 2, yPos + 20, { align: 'center' });
                    });
                    
                    yPos += 35;
                }
                
                // Charts Section
                if (document.getElementById('includeCharts').checked) {
                    statusEl.textContent = 'Capturing charts (high resolution)...';
                    
                    // Attempts Bar Chart (high-res)
                    const chart1Canvas = document.getElementById('attemptsBarChart');
                    const chart1Image = getHighResChartImage(chart1Canvas, 2);
                    
                    pdf.setTextColor(51, 51, 51);
                    pdf.setFontSize(12);
                    pdf.setFont('helvetica', 'bold');
                    pdf.text('📊 Quiz Attempts by Book', margin, yPos);
                    yPos += 5;
                    
                    const chartWidth = pageWidth - margin * 2;
                    const chartHeight = 60;
                    pdf.addImage(chart1Image, 'PNG', margin, yPos, chartWidth, chartHeight);
                    yPos += chartHeight + 10;
                    
                    // Score Distribution Pie Chart (high-res)
                    if (yPos > pageHeight - 80) {
                        pdf.addPage();
                        yPos = margin;
                    }
                    
                    const chart2Canvas = document.getElementById('scoresPieChart');
                    const chart2Image = getHighResChartImage(chart2Canvas, 2);
                    
                    pdf.setFontSize(12);
                    pdf.setFont('helvetica', 'bold');
                    pdf.text('🎯 Score Distribution', margin, yPos);
                    yPos += 5;
                    
                    pdf.addImage(chart2Image, 'PNG', margin, yPos, chartWidth / 2, 55);
                    
                    // Genre Pie Chart (high-res)
                    const chart4Canvas = document.getElementById('genrePieChart');
                    const chart4Image = getHighResChartImage(chart4Canvas, 2);
                    
                    pdf.text('📚 Books by Genre', margin + chartWidth / 2 + 10, yPos - 5);
                    pdf.addImage(chart4Image, 'PNG', margin + chartWidth / 2 + 10, yPos, chartWidth / 2 - 10, 55);
                    
                    yPos += 65;
                    
                    // Grade Level Performance Chart (high-res) - NEW
                    if (gradeData && gradeData.length > 0) {
                        if (yPos > pageHeight - 90) {
                            pdf.addPage();
                            yPos = margin;
                        }
                        
                        const gradeChartCanvas = document.getElementById('gradeLevelChart');
                        const gradeChartImage = getHighResChartImage(gradeChartCanvas, 2);
                        
                        pdf.setFontSize(12);
                        pdf.setFont('helvetica', 'bold');
                        pdf.text('📚 Performance by Grade Level', margin, yPos);
                        yPos += 5;
                        
                        pdf.addImage(gradeChartImage, 'PNG', margin, yPos, chartWidth, 70);
                        yPos += 80;
                    }
                }
                
                // Performance Insights
                if (document.getElementById('includePerformance').checked) {
                    statusEl.textContent = 'Adding performance insights...';
                    
                    if (yPos > pageHeight - 60) {
                        pdf.addPage();
                        yPos = margin;
                    }
                    
                    pdf.setFontSize(14);
                    pdf.setFont('helvetica', 'bold');
                    pdf.setTextColor(51, 51, 51);
                    pdf.text('🏆 Book Performance Insights', margin, yPos);
                    yPos += 10;
                    
                    <?php if ($best_book): ?>
                    // Best Performing Book
                    pdf.setFillColor(40, 167, 69);
                    pdf.roundedRect(margin, yPos, (pageWidth - margin * 2) / 2 - 5, 35, 3, 3, 'F');
                    
                    pdf.setTextColor(255, 255, 255);
                    pdf.setFontSize(9);
                    pdf.text('Best Performing Book', margin + 5, yPos + 8);
                    pdf.setFontSize(11);
                    pdf.setFont('helvetica', 'bold');
                    const bestTitle = '<?php echo addslashes($best_book['title']); ?>';
                    pdf.text(bestTitle.substring(0, 30) + (bestTitle.length > 30 ? '...' : ''), margin + 5, yPos + 18);
                    pdf.setFontSize(9);
                    pdf.setFont('helvetica', 'normal');
                    pdf.text('Pass Rate: <?php echo $best_book['pass_rate']; ?>% | Avg: <?php echo $best_book['avg_score']; ?>%', margin + 5, yPos + 28);
                    <?php endif; ?>
                    
                    <?php if ($challenging_book): ?>
                    // Most Challenging Book
                    pdf.setFillColor(220, 53, 69);
                    pdf.roundedRect(margin + (pageWidth - margin * 2) / 2 + 5, yPos, (pageWidth - margin * 2) / 2 - 5, 35, 3, 3, 'F');
                    
                    pdf.setTextColor(255, 255, 255);
                    pdf.setFontSize(9);
                    pdf.text('Most Challenging Book', margin + (pageWidth - margin * 2) / 2 + 10, yPos + 8);
                    pdf.setFontSize(11);
                    pdf.setFont('helvetica', 'bold');
                    const challengingTitle = '<?php echo addslashes($challenging_book['title']); ?>';
                    pdf.text(challengingTitle.substring(0, 30) + (challengingTitle.length > 30 ? '...' : ''), margin + (pageWidth - margin * 2) / 2 + 10, yPos + 18);
                    pdf.setFontSize(9);
                    pdf.setFont('helvetica', 'normal');
                    pdf.text('Pass Rate: <?php echo $challenging_book['pass_rate']; ?>% | Avg: <?php echo $challenging_book['avg_score']; ?>%', margin + (pageWidth - margin * 2) / 2 + 10, yPos + 28);
                    <?php endif; ?>
                    
                    yPos += 45;
                }
                
                // Book Inventory Summary
                if (document.getElementById('includeInventory').checked) {
                    statusEl.textContent = 'Adding inventory summary...';
                    
                    if (yPos > pageHeight - 80) {
                        pdf.addPage();
                        yPos = margin;
                    }
                    
                    pdf.setFontSize(14);
                    pdf.setFont('helvetica', 'bold');
                    pdf.setTextColor(51, 51, 51);
                    pdf.text('📚 Book Inventory (Top 10)', margin, yPos);
                    yPos += 8;
                    
                    // Table header
                    pdf.setFillColor(102, 126, 234);
                    pdf.rect(margin, yPos, pageWidth - margin * 2, 8, 'F');
                    
                    pdf.setTextColor(255, 255, 255);
                    pdf.setFontSize(8);
                    pdf.setFont('helvetica', 'bold');
                    
                    const colWidths = [50, 40, 30, 25, 25];
                    const headers = ['Title', 'Author', 'Genre', 'Status', 'Avg Score'];
                    let xPos = margin + 3;
                    
                    headers.forEach((header, i) => {
                        pdf.text(header, xPos, yPos + 5.5);
                        xPos += colWidths[i];
                    });
                    
                    yPos += 8;
                    
                    // Table rows
                    pdf.setTextColor(51, 51, 51);
                    pdf.setFont('helvetica', 'normal');
                    
                    <?php 
                    $topBooks = array_slice($book_inventory, 0, 10);
                    foreach ($topBooks as $index => $book): 
                    ?>
                    if (yPos > pageHeight - 20) {
                        pdf.addPage();
                        yPos = margin;
                    }
                    
                    <?php if ($index % 2 == 0): ?>
                    pdf.setFillColor(248, 249, 250);
                    pdf.rect(margin, yPos, pageWidth - margin * 2, 7, 'F');
                    <?php endif; ?>
                    
                    xPos = margin + 3;
                    const row<?php echo $index; ?> = [
                        '<?php echo addslashes(mb_substr($book['title'], 0, 25)) . (mb_strlen($book['title']) > 25 ? '...' : ''); ?>',
                        '<?php echo addslashes(mb_substr($book['author'], 0, 20)) . (mb_strlen($book['author']) > 20 ? '...' : ''); ?>',
                        '<?php echo addslashes($book['genre'] ?? 'N/A'); ?>',
                        '<?php echo $book['is_available'] ? 'Available' : 'Unavailable'; ?>',
                        '<?php echo round($book['avg_score'], 1); ?>%'
                    ];
                    
                    row<?php echo $index; ?>.forEach((cell, i) => {
                        pdf.text(cell, xPos, yPos + 5);
                        xPos += colWidths[i];
                    });
                    
                    yPos += 7;
                    <?php endforeach; ?>
                    
                    yPos += 10;
                }
                
                // Footer
                const totalPages = pdf.internal.getNumberOfPages();
                for (let i = 1; i <= totalPages; i++) {
                    pdf.setPage(i);
                    pdf.setFontSize(8);
                    pdf.setTextColor(150, 150, 150);
                    pdf.text('Library Hub - Librarian Report | Page ' + i + ' of ' + totalPages, pageWidth / 2, pageHeight - 8, { align: 'center' });
                }
                
                statusEl.textContent = 'Saving PDF...';
                
                // Generate filename with date
                const fileName = 'LibraryHub_Report_' + new Date().toISOString().slice(0, 10) + '.pdf';
                pdf.save(fileName);
                
                overlay.classList.remove('active');
                alert('✅ Report exported successfully!\n\nFile: ' + fileName);
                
            } catch (error) {
                console.error('PDF Generation Error:', error);
                overlay.classList.remove('active');
                alert('❌ Error generating PDF: ' + error.message);
            }
        }

        function previewReport() {
            const modal = document.getElementById('reportPreviewModal');
            const body = document.getElementById('reportPreviewBody');
            
            let html = '<div style="font-family: Arial, sans-serif;">';
            
            // Header
            html += '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; text-align: center; margin-bottom: 20px;">';
            html += '<h2 style="margin: 0;">📚 Library Hub - Librarian Report</h2>';
            html += '<p style="margin: 10px 0 0; opacity: 0.9;">Generated: ' + new Date().toLocaleString() + '</p>';
            
            // Add selected grade levels to preview header
            const selectedGrades = getSelectedGrades();
            if (selectedReportType === 'grade' && selectedGrades.length > 0 && selectedGrades.length < 6) {
                html += '<p style="margin: 8px 0 0; opacity: 0.85; font-size: 14px;">📚 Grade Levels: ' + selectedGrades.map(g => 'Grade ' + g).join(', ') + '</p>';
            }
            
            html += '</div>';
            
            // Statistics
            if (document.getElementById('includeStats').checked) {
                html += '<h3 style="color: #333; border-bottom: 2px solid #667eea; padding-bottom: 8px;">📊 Statistics Overview</h3>';
                html += '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px;">';
                html += '<div style="background: #667eea; color: white; padding: 15px; border-radius: 10px; text-align: center;"><strong style="font-size: 24px;">' + statsData.totalBooks + '</strong><br><small>Total Books</small></div>';
                html += '<div style="background: #28a745; color: white; padding: 15px; border-radius: 10px; text-align: center;"><strong style="font-size: 24px;">' + statsData.availableBooks + '</strong><br><small>Available</small></div>';
                html += '<div style="background: #dc3545; color: white; padding: 15px; border-radius: 10px; text-align: center;"><strong style="font-size: 24px;">' + statsData.unavailableBooks + '</strong><br><small>Unavailable</small></div>';
                html += '<div style="background: #ffc107; color: #333; padding: 15px; border-radius: 10px; text-align: center;"><strong style="font-size: 24px;">' + statsData.totalAttempts + '</strong><br><small>Quiz Attempts</small></div>';
                html += '</div>';
            }
            
            // Charts indicator
            if (document.getElementById('includeCharts').checked) {
                html += '<h3 style="color: #333; border-bottom: 2px solid #667eea; padding-bottom: 8px;">📈 Charts & Analytics</h3>';
                html += '<p style="color: #666; background: #f8f9fa; padding: 15px; border-radius: 8px;">✅ Charts will be included in PDF export (Quiz Attempts, Score Distribution, Monthly Activity, Genre Distribution)</p>';
            }
            
            // Performance
            if (document.getElementById('includePerformance').checked) {
                html += '<h3 style="color: #333; border-bottom: 2px solid #667eea; padding-bottom: 8px;">🏆 Book Performance Insights</h3>';
                html += '<p style="color: #666; background: #f8f9fa; padding: 15px; border-radius: 8px;">✅ Best performing and most challenging books will be included</p>';
            }
            
            // Inventory
            if (document.getElementById('includeInventory').checked) {
                html += '<h3 style="color: #333; border-bottom: 2px solid #667eea; padding-bottom: 8px;">📚 Book Inventory</h3>';
                html += '<p style="color: #666; background: #f8f9fa; padding: 15px; border-radius: 8px;">✅ Top 10 books from inventory will be included</p>';
            }
            
            // Activity
            if (document.getElementById('includeActivity').checked) {
                html += '<h3 style="color: #333; border-bottom: 2px solid #667eea; padding-bottom: 8px;">📝 Recent Activity</h3>';
                html += '<p style="color: #666; background: #f8f9fa; padding: 15px; border-radius: 8px;">✅ Recent quiz activity summary will be included</p>';
            }
            
            html += '</div>';
            
            body.innerHTML = html;
            document.getElementById('reportPreviewModal').classList.add('active');
        }

        function closePreviewModal() {
            document.getElementById('reportPreviewModal').classList.remove('active');
        }

        // Close preview modal when clicking outside
        document.getElementById('reportPreviewModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePreviewModal();
            }
        });

        // Initialize charts when boot loader finishes
        window.addEventListener('load', function() {
            // ...existing boot loader code...

            // Initialize charts after a short delay
            setTimeout(initializeCharts, 500);
        });

        // Boot Animation Script - Updated
        window.addEventListener('load', function() {
            const bootLoader = document.getElementById('bootLoader');
            const mainContent = document.getElementById('mainContent');
            
            const minLoadingTime = 2000;
            const startTime = Date.now();
            
            function hideBootLoader() {
                const elapsedTime = Date.now() - startTime;
                const remainingTime = Math.max(0, minLoadingTime - elapsedTime);
                
                // Check if boot animation has been shown this session
                if (sessionStorage.getItem('bootAnimationShown')) {
                    bootLoader.style.display = 'none';
                    mainContent.classList.add('show');
                    setTimeout(initializeCharts, 300);
                    return;
                }
                
                sessionStorage.setItem('bootAnimationShown', 'true');
                setTimeout(() => {
                    bootLoader.classList.add('fade-out');
                    
                    setTimeout(() => {
                        bootLoader.style.display = 'none';
                        mainContent.classList.add('show');
                        
                        // Initialize charts after content is visible
                        setTimeout(initializeCharts, 300);
                    }, 800);
                }, remainingTime);
            }
            
            hideBootLoader();
        });
    </script>
    
    <!-- DataTables (local) and other vendor scripts -->
    <script src="vendor/jquery-3.7.1.min.js"></script>
    <script src="vendor/jquery.dataTables.min.js"></script>
    <script src="vendor/sweetalert2.min.js"></script>
    <!-- Error Handler -->
    <script src="../js/error-handler.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Book Inventory Table
            if ($('#bookInventoryTable').length) {
                $('#bookInventoryTable').DataTable({
                    pageLength: 10,
                    lengthMenu: [[10, 25, 30, -1], [10, 25, 30, "All"]],
                    order: [[1, 'asc']],
                    columnDefs: [
                        { orderable: false, targets: -1 }
                    ],
                    language: {
                        search: "🔍 Search:",
                        lengthMenu: "Show _MENU_ books",
                        info: "Showing _START_ to _END_ of _TOTAL_ books",
                        emptyTable: "No books in inventory"
                    }
                });
            }
        });
    </script>
</body>
</html>