-- Migration: Add instructor deloading details
-- Purpose: store Nature of Designation + Units Deloading values

ALTER TABLE instructors
    ADD COLUMN designation VARCHAR(150) NULL AFTER program_id,
    ADD COLUMN designation_units DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER designation,
    ADD COLUMN research_extension VARCHAR(150) NULL AFTER designation_units,
    ADD COLUMN research_extension_units DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER research_extension,
    ADD COLUMN special_assignment VARCHAR(150) NULL AFTER research_extension_units,
    ADD COLUMN special_assignment_units DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER special_assignment;
