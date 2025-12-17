-- Add new_profile_picture column to profile_edit_requests table
-- Run this migration if you get error: Unknown column 'new_profile_picture'

ALTER TABLE `profile_edit_requests` 
ADD COLUMN `new_profile_picture` VARCHAR(255) AFTER `new_grade_level`;
