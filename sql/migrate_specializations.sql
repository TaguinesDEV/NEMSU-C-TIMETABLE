-- Migration: Support multiple specializations per instructor (up to 3)

-- Create new specializations table
CREATE TABLE IF NOT EXISTS specializations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    specialization_name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT
);

-- Create junction table for instructor-specialization mapping
CREATE TABLE IF NOT EXISTS instructor_specializations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    instructor_id INT NOT NULL,
    specialization_id INT NOT NULL,
    priority INT DEFAULT 1,
    UNIQUE KEY unique_instructor_spec (instructor_id, specialization_id),
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES specializations(id) ON DELETE CASCADE
);

-- Migrate existing specialization data to new tables
INSERT INTO specializations (specialization_name) 
SELECT DISTINCT specialization FROM instructors 
WHERE specialization IS NOT NULL AND specialization != '';

-- Migrate instructor specializations (assign all existing specs as priority 1)
INSERT INTO instructor_specializations (instructor_id, specialization_id, priority)
SELECT i.id, s.id, 1 
FROM instructors i
JOIN specializations s ON i.specialization = s.specialization_name
WHERE i.specialization IS NOT NULL AND i.specialization != '';

-- Keep the old column for backward compatibility (can be removed later)
-- ALTER TABLE instructors DROP COLUMN specialization;
