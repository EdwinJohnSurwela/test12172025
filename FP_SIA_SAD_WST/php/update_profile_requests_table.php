<?php
/**
 * Run this script once to add the new_profile_picture column to profile_edit_requests table
 */
require_once 'config.php';

// Add new_profile_picture column if it doesn't exist
$check_column = $conn->query("SHOW COLUMNS FROM profile_edit_requests LIKE 'new_profile_picture'");

if ($check_column->num_rows === 0) {
    $alter_sql = "ALTER TABLE profile_edit_requests ADD COLUMN new_profile_picture VARCHAR(255) DEFAULT NULL AFTER new_grade_level";
    
    if ($conn->query($alter_sql)) {
        echo "✅ Successfully added 'new_profile_picture' column to profile_edit_requests table.<br>";
    } else {
        echo "❌ Error adding column: " . $conn->error . "<br>";
    }
} else {
    echo "ℹ️ Column 'new_profile_picture' already exists.<br>";
}

// Create pending profile pictures directory if it doesn't exist
$pending_dir = __DIR__ . '/../uploads/pending_profile_pictures/';
if (!file_exists($pending_dir)) {
    if (mkdir($pending_dir, 0755, true)) {
        echo "✅ Created pending_profile_pictures directory.<br>";
    } else {
        echo "❌ Failed to create pending_profile_pictures directory.<br>";
    }
} else {
    echo "ℹ️ Pending profile pictures directory already exists.<br>";
}

// Ensure profile_pictures directory exists
$approved_dir = __DIR__ . '/../uploads/profile_pictures/';
if (!file_exists($approved_dir)) {
    if (mkdir($approved_dir, 0755, true)) {
        echo "✅ Created profile_pictures directory.<br>";
    } else {
        echo "❌ Failed to create profile_pictures directory.<br>";
    }
} else {
    echo "ℹ️ Profile pictures directory already exists.<br>";
}

echo "<br><strong>Database update complete!</strong><br>";
echo "<a href='admin.php'>Go to Admin Panel</a>";
?>
