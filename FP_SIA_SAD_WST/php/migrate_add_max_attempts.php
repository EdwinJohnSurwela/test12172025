<?php
require_once 'config.php';

// Simple migration: adds max_quiz_attempts column to books if missing
try {
    $columns = [];
    $res = $conn->query("SHOW COLUMNS FROM books");
    while ($row = $res->fetch_assoc()) {
        $columns[] = $row['Field'];
    }

    if (in_array('max_quiz_attempts', $columns)) {
        echo "Column max_quiz_attempts already exists.\n";
        exit(0);
    }

    $sql = "ALTER TABLE books ADD COLUMN max_quiz_attempts INT NULL DEFAULT NULL";
    if ($conn->query($sql) === TRUE) {
        echo "Added column max_quiz_attempts to books.\n";
        exit(0);
    } else {
        echo "Failed to add column: " . $conn->error . "\n";
        exit(2);
    }
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(3);
}
