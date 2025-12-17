-- Run this SQL to add missing columns to quiz_attempts table

-- Add time_taken column if it doesn't exist (stores seconds)
ALTER TABLE quiz_attempts 
ADD COLUMN IF NOT EXISTS time_taken INT DEFAULT 0;

-- Add status column for tracking flagged attempts
ALTER TABLE quiz_attempts 
ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'completed';

-- Add tab switch tracking columns
ALTER TABLE quiz_attempts 
ADD COLUMN IF NOT EXISTS tab_switch_count INT DEFAULT 0;

ALTER TABLE quiz_attempts 
ADD COLUMN IF NOT EXISTS tab_switch_log TEXT NULL;

-- Add index for filtering by status
CREATE INDEX IF NOT EXISTS idx_quiz_status ON quiz_attempts(status);
