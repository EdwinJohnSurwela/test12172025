-- Run this SQL to add missing columns to user_rewards table

-- Add earned_at column if it doesn't exist
ALTER TABLE user_rewards 
ADD COLUMN IF NOT EXISTS earned_at DATETIME DEFAULT CURRENT_TIMESTAMP;

-- If the table doesn't have a primary key, add one
-- ALTER TABLE user_rewards ADD COLUMN IF NOT EXISTS user_reward_id INT AUTO_INCREMENT PRIMARY KEY;

-- Update any NULL earned_at values to current timestamp
UPDATE user_rewards SET earned_at = NOW() WHERE earned_at IS NULL;
