-- =====================================================
-- ADD QUIZ TIMER COLUMN TO BOOKS TABLE
-- Run this migration to add quiz timer functionality
-- =====================================================

-- Add quiz time limit columns to books table
-- quiz_time_limit stores the time in seconds (NULL means no limit)
ALTER TABLE books 
ADD COLUMN quiz_time_limit INT NULL DEFAULT NULL COMMENT 'Quiz time limit in seconds, NULL means no limit';

-- Add timer tracking to quiz_attempts
ALTER TABLE quiz_attempts
ADD COLUMN time_expired BOOLEAN DEFAULT FALSE COMMENT 'Whether the quiz was auto-submitted due to time expiry';

-- Add library card ID column to users (for the new library card system)
ALTER TABLE users
ADD COLUMN library_card_id VARCHAR(20) NULL AFTER student_id;

-- Create index for faster library card lookups
CREATE INDEX idx_library_card ON users(library_card_id);

-- Update existing students to have library card IDs (format: YYYY-XXX)
-- This creates card IDs based on user creation order
SET @row_number = 0;
UPDATE users 
SET library_card_id = CONCAT(YEAR(created_at), '-', LPAD(@row_number := @row_number + 1, 3, '0'))
WHERE user_type = 'student' AND library_card_id IS NULL;
