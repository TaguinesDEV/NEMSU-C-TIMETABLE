-- Add subject type and support fractional hours for Major/Minor workload rules.
-- Major = 2.50 hours/week, Minor = 1.50 hours/week

ALTER TABLE subjects
    ADD COLUMN subject_type ENUM('major', 'minor') NOT NULL DEFAULT 'major' AFTER department;

ALTER TABLE subjects
    MODIFY hours_per_week DECIMAL(4,2) NOT NULL;

UPDATE subjects
SET subject_type = CASE
    WHEN LOWER(subject_code) LIKE '%gened%' THEN 'minor'
    ELSE 'major'
END
WHERE subject_type IS NULL OR subject_type = '';

UPDATE subjects
SET hours_per_week = CASE
    WHEN subject_type = 'minor' THEN 1.50
    ELSE 2.50
END;
