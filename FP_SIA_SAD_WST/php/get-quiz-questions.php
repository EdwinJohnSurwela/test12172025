<?php
require_once 'config.php';

// Check if user is logged in as librarian or teacher
check_user_type(['librarian', 'teacher']);

header('Content-Type: application/json');

if (!isset($_GET['book_id'])) {
    echo json_encode(['success' => false, 'message' => 'Book ID is required']);
    exit();
}

$book_id = (int)$_GET['book_id'];

// Fetch quiz timer from books table
$timer_sql = "SELECT COALESCE(quiz_time_limit, 0) as quiz_time_limit FROM books WHERE book_id = ?";
$timer_stmt = $conn->prepare($timer_sql);
$timer_stmt->bind_param("i", $book_id);
$timer_stmt->execute();
$timer_result = $timer_stmt->get_result();
$timer_data = $timer_result->fetch_assoc();
$quiz_time_limit = (int)($timer_data['quiz_time_limit'] ?? 0);
$timer_stmt->close();

// Fetch quiz questions for this book
$sql = "SELECT question_id, question_text, option_a, option_b, option_c, option_d, correct_answer, difficulty_level, 
        COALESCE(question_type, 'multiple_choice') as question_type
        FROM quiz_questions 
        WHERE book_id = ? 
        ORDER BY question_id ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $book_id);
$stmt->execute();
$result = $stmt->get_result();

$questions = [];
while ($row = $result->fetch_assoc()) {
    $questions[] = $row;
}

$stmt->close();

// Always return quiz_time_limit even if no questions
echo json_encode([
    'success' => count($questions) > 0,
    'questions' => $questions,
    'quiz_time_limit' => $quiz_time_limit,
    'message' => count($questions) > 0 ? '' : 'No quiz questions found for this book'
]);
?>
