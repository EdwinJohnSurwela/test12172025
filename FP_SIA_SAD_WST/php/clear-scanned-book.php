<?php
session_start();

header('Content-Type: application/json');

// Clear the scanned book from session
if (isset($_SESSION['scanned_book'])) {
    unset($_SESSION['scanned_book']);
}

echo json_encode([
    'success' => true,
    'message' => 'Scanned book cleared'
]);
?>
<?php
// Fully clear all quiz/book-related session data
unset($_SESSION['scanned_book']);
unset($_SESSION['book_id']);
unset($_SESSION['book_title']);

header("Location: index.php");
exit();
?>
