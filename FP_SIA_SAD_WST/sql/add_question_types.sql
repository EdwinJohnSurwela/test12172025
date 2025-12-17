ALTER TABLE quiz_questions 
ADD COLUMN question_type ENUM('multiple_choice', 'fill_blank', 'identification', 'enumeration') 
DEFAULT 'multiple_choice' AFTER correct_answer;

-- For enumeration questions, correct_answer should contain comma-separated values
-- Example: "Apple,Banana,Orange" for an enumeration asking for 3 fruits
-- The order doesn't matter for enumeration validation

-- For fill_blank, correct_answer contains the exact answer (case-insensitive matching)
-- For identification, correct_answer contains the exact answer (case-insensitive matching)
