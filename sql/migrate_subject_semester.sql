-- Migration: add semester column to subjects
-- Purpose: store semester as 1st Semester, 2nd Semester, or Summer

ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS semester ENUM('1st Semester','2nd Semester','Summer') NOT NULL DEFAULT '1st Semester' AFTER subject_type;
