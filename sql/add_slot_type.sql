-- Add slot_type column to time_slots table
-- This allows marking Saturday slots as "makeup class" or "summer class"
ALTER TABLE time_slots 
ADD COLUMN slot_type ENUM('regular', 'makeup', 'summer') DEFAULT 'regular' AFTER end_time;

-- Update existing Saturday slots to be marked as regular (can be changed later)
UPDATE time_slots SET slot_type = 'regular' WHERE day = 'Saturday';
