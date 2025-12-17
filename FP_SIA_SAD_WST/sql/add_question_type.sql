-- Add question_type column if it doesn't exist
ALTER TABLE quiz_questions 
ADD COLUMN IF NOT EXISTS question_type ENUM('multiple_choice', 'fill_blank', 'identification', 'enumeration') 
DEFAULT 'multiple_choice' AFTER correct_answer;

-- For enumeration: correct_answer should be comma-separated (e.g., "Apple,Banana,Orange")
-- For fill_blank/identification: correct_answer is the expected text answer
-- For multiple_choice: correct_answer is A, B, C, or D
