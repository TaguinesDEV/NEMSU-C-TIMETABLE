-- Consolidated migration (run this one file only)
-- Covers: programs/program_chairs, subject fields, instructor fields,
-- specializations, slot_type, and related foreign keys.

SET @db := DATABASE();

-- =========================================================
-- 1) PROGRAMS + PROGRAM CHAIRS
-- =========================================================

CREATE TABLE IF NOT EXISTS programs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    program_name VARCHAR(100) NOT NULL,
    program_code VARCHAR(20) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO programs (program_name, program_code) VALUES
('Bachelor of Science in Computer Science', 'BSCS'),
('Bachelor of Science in Information Technology', 'BSIT'),
('Bachelor of Science in Business Administration', 'BSBA'),
('Bachelor of Science in Education', 'BSED'),
('Bachelor of Arts in Communication', 'ABCOM')
ON DUPLICATE KEY UPDATE program_name = VALUES(program_name);

CREATE TABLE IF NOT EXISTS program_chairs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE NOT NULL,
    program_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_program_chair (program_id)
);

-- subjects.program_id
SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'program_id'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE subjects ADD COLUMN program_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'subjects'
      AND COLUMN_NAME = 'program_id'
      AND REFERENCED_TABLE_NAME = 'programs'
);
SET @sql := IF(@cnt = 0,
    'ALTER TABLE subjects ADD CONSTRAINT fk_subjects_program_id FOREIGN KEY (program_id) REFERENCES programs(id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- instructors.program_id
SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'program_id'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE instructors ADD COLUMN program_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'instructors'
      AND COLUMN_NAME = 'program_id'
      AND REFERENCED_TABLE_NAME = 'programs'
);
SET @sql := IF(@cnt = 0,
    'ALTER TABLE instructors ADD CONSTRAINT fk_instructors_program_id FOREIGN KEY (program_id) REFERENCES programs(id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- schedule_jobs.program_id
SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'schedule_jobs' AND COLUMN_NAME = 'program_id'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE schedule_jobs ADD COLUMN program_id INT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'schedule_jobs'
      AND COLUMN_NAME = 'program_id'
      AND REFERENCED_TABLE_NAME = 'programs'
);
SET @sql := IF(@cnt = 0,
    'ALTER TABLE schedule_jobs ADD CONSTRAINT fk_schedule_jobs_program_id FOREIGN KEY (program_id) REFERENCES programs(id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- 2) SUBJECTS (type/hours/lecture-lab/semester)
-- =========================================================

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'subject_type'
);
SET @sql := IF(
    @cnt = 0,
    'ALTER TABLE subjects ADD COLUMN subject_type ENUM(''major'', ''minor'') NOT NULL DEFAULT ''major''',
    'ALTER TABLE subjects MODIFY COLUMN subject_type ENUM(''major'', ''minor'') NOT NULL DEFAULT ''major'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE subjects MODIFY hours_per_week DECIMAL(4,2) NOT NULL;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'lecture_hours'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE subjects ADD COLUMN lecture_hours DECIMAL(4,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'lab_hours'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE subjects ADD COLUMN lab_hours DECIMAL(4,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'semester'
);
SET @sql := IF(
    @cnt = 0,
    'ALTER TABLE subjects ADD COLUMN semester ENUM(''1st Semester'', ''2nd Semester'', ''Summer'') NOT NULL DEFAULT ''1st Semester''',
    'ALTER TABLE subjects MODIFY COLUMN semester ENUM(''1st Semester'', ''2nd Semester'', ''Summer'') NOT NULL DEFAULT ''1st Semester'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'year_level'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE subjects ADD COLUMN year_level INT NULL AFTER semester', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'prerequisites'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE subjects ADD COLUMN prerequisites TEXT NULL AFTER year_level', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill only rows that still have no split.
UPDATE subjects
SET lecture_hours = COALESCE(hours_per_week, 0),
    lab_hours = 0.00
WHERE COALESCE(lecture_hours, 0) = 0.00
  AND COALESCE(lab_hours, 0) = 0.00;

UPDATE subjects
SET semester = '1st Semester'
WHERE semester IS NULL OR semester = '';

-- =========================================================
-- 3) INSTRUCTORS (deloading + status)
-- =========================================================

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'designation'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE instructors ADD COLUMN designation VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'designation_units'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE instructors ADD COLUMN designation_units DECIMAL(5,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'research_extension'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE instructors ADD COLUMN research_extension VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'research_extension_units'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE instructors ADD COLUMN research_extension_units DECIMAL(5,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'special_assignment'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE instructors ADD COLUMN special_assignment VARCHAR(150) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'special_assignment_units'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE instructors ADD COLUMN special_assignment_units DECIMAL(5,2) NOT NULL DEFAULT 0.00', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'status'
);
SET @sql := IF(
    @cnt = 0,
    'ALTER TABLE instructors ADD COLUMN status ENUM(''Permanent'', ''Contractual'', ''Temporary'') NULL',
    'ALTER TABLE instructors MODIFY COLUMN status ENUM(''Permanent'', ''Contractual'', ''Temporary'') NULL'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'instructors' AND COLUMN_NAME = 'photo'
);
SET @sql := IF(@cnt = 0, 'ALTER TABLE instructors ADD COLUMN photo VARCHAR(255) NULL AFTER service_years', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================
-- 4) SPECIALIZATIONS (many-to-many)
-- =========================================================

CREATE TABLE IF NOT EXISTS specializations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    specialization_name VARCHAR(100) UNIQUE NOT NULL,
    description TEXT
);

CREATE TABLE IF NOT EXISTS instructor_specializations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    instructor_id INT NOT NULL,
    specialization_id INT NOT NULL,
    priority INT DEFAULT 1,
    UNIQUE KEY unique_instructor_spec (instructor_id, specialization_id),
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
    FOREIGN KEY (specialization_id) REFERENCES specializations(id) ON DELETE CASCADE
);

INSERT IGNORE INTO specializations (specialization_name)
SELECT DISTINCT specialization
FROM instructors
WHERE specialization IS NOT NULL AND specialization <> '';

INSERT IGNORE INTO instructor_specializations (instructor_id, specialization_id, priority)
SELECT i.id, s.id, 1
FROM instructors i
JOIN specializations s ON i.specialization = s.specialization_name
WHERE i.specialization IS NOT NULL AND i.specialization <> '';

-- =========================================================
-- 5) TIME SLOTS (slot_type)
-- =========================================================

SET @cnt := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'time_slots' AND COLUMN_NAME = 'slot_type'
);
SET @sql := IF(
    @cnt = 0,
    'ALTER TABLE time_slots ADD COLUMN slot_type ENUM(''regular'', ''makeup'', ''summer'') NOT NULL DEFAULT ''regular''',
    'ALTER TABLE time_slots MODIFY COLUMN slot_type ENUM(''regular'', ''makeup'', ''summer'') NOT NULL DEFAULT ''regular'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE time_slots
SET slot_type = 'regular'
WHERE day = 'Saturday' AND (slot_type IS NULL OR slot_type = '');

-- Done
SELECT 'Consolidated migration completed.' AS message;
