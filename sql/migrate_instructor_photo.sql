-- Migration: add instructor photo path support
-- Purpose: store uploaded instructor profile photo paths

ALTER TABLE instructors
    ADD COLUMN IF NOT EXISTS photo VARCHAR(255) NULL AFTER service_years;
