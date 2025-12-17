CREATE TABLE IF NOT EXISTS `profile_edit_requests` (
  `request_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `new_full_name` VARCHAR(100) NOT NULL,
  `new_email` VARCHAR(100) NOT NULL,
  `new_student_id` VARCHAR(50),
  `new_grade_level` VARCHAR(10),
  `new_profile_picture` VARCHAR(255),
  `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
  `requested_at` DATETIME NOT NULL,
  `approved_by` INT,
  `approved_at` DATETIME,
  `rejected_by` INT,
  `rejected_at` DATETIME,
  `rejection_reason` TEXT,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  FOREIGN KEY (`rejected_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
