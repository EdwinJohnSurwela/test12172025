-- Run this SQL to add tab switch tracking columns to quiz_attempts table

ALTER TABLE quiz_attempts 
ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'completed',
ADD COLUMN IF NOT EXISTS tab_switch_count INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS tab_switch_log TEXT NULL;

-- Add index for filtering flagged attempts
CREATE INDEX IF NOT EXISTS idx_quiz_status ON quiz_attempts(status);
