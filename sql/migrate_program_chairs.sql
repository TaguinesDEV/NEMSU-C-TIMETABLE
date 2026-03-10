-- Migration: Add Program Chairs support

-- Create programs table (using departments as programs)
CREATE TABLE IF NOT EXISTS programs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_name VARCHAR(100) NOT NULL,
    program_code VARCHAR(20) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample programs if not exist
INSERT INTO programs (program_name, program_code) VALUES
('Bachelor of Science in Computer Science', 'BSCS'),
('Bachelor of Science in Information Technology', 'BSIT'),
('Bachelor of Science in Business Administration', 'BSBA'),
('Bachelor of Science in Education', 'BSED'),
('Bachelor of Arts in Communication', 'ABCOM')
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

-- Add program_id column to users table if not exists
-- ALTER TABLE users ADD COLUMN program_id INT NULL;
-- ALTER TABLE users ADD FOREIGN KEY (program_id) REFERENCES programs(id);

-- Create program_chairs table
CREATE TABLE IF NOT EXISTS program_chairs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    program_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_program_chair (program_id)
);

-- Add program_id to subjects table if not exists
-- ALTER TABLE subjects ADD COLUMN program_id INT NULL;
-- ALTER TABLE subjects ADD FOREIGN KEY (program_id) REFERENCES programs(id);

-- Add program_id to instructors table if not exists  
-- ALTER TABLE instructors ADD COLUMN program_id INT NULL;
-- ALTER TABLE instructors ADD FOREIGN KEY (program_id) REFERENCES programs(id);

-- Add program_id to schedule_jobs table if not exists
-- ALTER TABLE schedule_jobs ADD COLUMN program_id INT NULL;
-- ALTER TABLE schedule_jobs ADD FOREIGN KEY (program_id) REFERENCES programs(id);
