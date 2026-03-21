-- Migration: Add lecture/lab hour breakdown for subjects

ALTER TABLE subjects
    ADD COLUMN lecture_hours DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER hours_per_week,
    ADD COLUMN lab_hours DECIMAL(4,2) NOT NULL DEFAULT 0.00 AFTER lecture_hours;

-- Backfill existing rows: put all existing hours into lecture by default.
UPDATE subjects
SET lecture_hours = COALESCE(hours_per_week, 0),
    lab_hours = 0.00
WHERE lecture_hours = 0.00 AND lab_hours = 0.00;
