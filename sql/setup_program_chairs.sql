-- Complete migration for Program Chairs feature
-- Run this file to set up everything needed for Program Chairs

USE academic_scheduling;

-- 1. Create programs table (if not exists)
CREATE TABLE IF NOT EXISTS programs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_name VARCHAR(100) NOT NULL,
    program_code VARCHAR(20) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Insert sample programs (if not exist)
INSERT INTO programs (program_name, program_code) VALUES
('Bachelor of Science in Computer Science', 'BSCS'),
('Bachelor of Science in Information Technology', 'BSIT'),
('Bachelor of Science in Business Administration', 'BSBA'),
('Bachelor of Science in Education', 'BSED'),
('Bachelor of Arts in Communication', 'ABCOM')
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

-- 3. Add program_id to subjects table
ALTER TABLE subjects ADD COLUMN program_id INT NULL;
ALTER TABLE subjects ADD FOREIGN KEY (program_id) REFERENCES programs(id);

-- 4. Add program_id to instructors table  
ALTER TABLE instructors ADD COLUMN program_id INT NULL;
ALTER TABLE instructors ADD FOREIGN KEY (program_id) REFERENCES programs(id);

-- 5. Add program_id to schedule_jobs table
ALTER TABLE schedule_jobs ADD COLUMN program_id INT NULL;
ALTER TABLE schedule_jobs ADD FOREIGN KEY (program_id) REFERENCES programs(id);

-- 6. Create program_chairs table
CREATE TABLE IF NOT EXISTS program_chairs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    program_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_program_chair (program_id)
);

-- 7. Create demo program chair user (password: chair123)
INSERT INTO users (username, password, email, role, full_name) VALUES 
('chair', '$2y$10$8K1p/a0dL3LXMIgoEDRwu.Z6G6U0XqNQ5YM8dK5L6X5QYJX8YJD.e', 'chair@school.edu', 'program_chair', 'Program Chair')
ON DUPLICATE KEY UPDATE role = 'program_chair';

-- 8. Create program_chair record linking user to program (BSCS = id 1)
INSERT INTO program_chairs (user_id, program_id) 
SELECT id, 1 FROM users WHERE username = 'chair'
ON DUPLICATE KEY UPDATE program_id = 1;
