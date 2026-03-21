-- Migration: Add status to instructors
-- Purpose: store instructor status as Permanent, Contractual, or Temporary

ALTER TABLE instructors
    ADD COLUMN IF NOT EXISTS status ENUM('Permanent', 'Contractual', 'Temporary') NULL AFTER department;
