-- Generate IT Program - Semester 1, Year 1 - 8 Sections
-- This script creates subjects and assigns 8 sections (A through H) for IT 1st Year 1st Semester

-- First, insert the IT Program if it doesn't exist
INSERT IGNORE INTO programs (program_code, program_name, department_id, level, semester) 
VALUES ('IT', 'Information Technology', 1, 1, 1);

-- Get the program ID
SET @program_id = (SELECT id FROM programs WHERE program_code = 'IT' AND level = 1 AND semester = 1 LIMIT 1);

-- Insert 1st Year, 1st Semester IT Subjects
INSERT IGNORE INTO subjects (subject_code, subject_name, department, year_level, lecture_hours, lab_hours, subject_type) 
VALUES
-- Core Programming
('CS101', 'Introduction to Programming I', 'IT', 1, 3, 2, 'major'),
('CS102', 'Introduction to Programming II', 'IT', 1, 3, 2, 'major'),

-- Mathematics & Logic
('MATH101', 'Discrete Mathematics', 'IT', 1, 4, 0, 'major'),
('MATH102', 'Pre-Calculus', 'IT', 1, 3, 0, 'major'),

-- IT Fundamentals
('IT101', 'Computer Fundamentals', 'IT', 1, 3, 1, 'major'),
('IT102', 'Digital Systems', 'IT', 1, 3, 1, 'major'),

-- Core IT Courses
('IT103', 'Introduction to Databases', 'IT', 1, 2, 2, 'major'),
('IT104', 'Web Development Fundamentals', 'IT', 1, 2, 2, 'major'),

-- Communication & General Education
('ENG101', 'Technical Communication', 'IT', 1, 3, 0, 'general'),
('PE101', 'Physical Education I', 'IT', 1, 1, 1, 'general');

-- Create function to generate sections if it doesn't exist
DELIMITER $$
DROP PROCEDURE IF EXISTS generate_it_sections$$
CREATE PROCEDURE generate_it_sections()
BEGIN
    DECLARE section_char VARCHAR(1);
    DECLARE section_num INT DEFAULT 0;
    DECLARE section_letters VARCHAR(8) DEFAULT 'ABCDEFGH';
    DECLARE subject_id_var INT;
    DECLARE cursor_subjects CURSOR FOR 
        SELECT id FROM subjects WHERE department = 'IT' AND year_level = 1;
    
    -- Loop through each section (A-H)
    WHILE section_num < 8 DO
        SET section_char = SUBSTRING(section_letters, section_num + 1, 1);
        
        -- Loop through each subject
        OPEN cursor_subjects;
        subject_loop: LOOP
            FETCH cursor_subjects INTO subject_id_var;
            
            -- Exit if no more subjects
            IF subject_id_var IS NULL THEN
                LEAVE subject_loop;
            END IF;
            
            -- Insert section if it doesn't already exist
            INSERT IGNORE INTO sections (subject_id, section_name, year_level, semester, total_students)
            VALUES (subject_id_var, section_char, 1, 1, 40);
        END LOOP subject_loop;
        
        CLOSE cursor_subjects;
        SET section_num = section_num + 1;
    END WHILE;
END$$
DELIMITER ;

-- Execute the procedure
CALL generate_it_sections();

-- Display created subjects and sections
SELECT 
    s.subject_code,
    s.subject_name,
    s.lecture_hours,
    s.lab_hours,
    COUNT(sec.id) as num_sections
FROM subjects s
LEFT JOIN sections sec ON s.id = sec.subject_id
WHERE s.department = 'IT' AND s.year_level = 1
GROUP BY s.id, s.subject_code, s.subject_name, s.lecture_hours, s.lab_hours
ORDER BY s.subject_code;

-- Show statistics
SELECT COUNT(*) as total_subjects FROM subjects WHERE department = 'IT' AND year_level = 1;
SELECT COUNT(*) as total_sections FROM sections WHERE year_level = 1 AND semester = 1;
