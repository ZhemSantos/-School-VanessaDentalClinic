-- Database Migration V2: Add Title and Rename Doctor to Dentist in Notifications
-- Run this in phpMyAdmin

-- 1. Add 'title' column
ALTER TABLE tbl_notifications
ADD COLUMN title VARCHAR(50) NOT NULL AFTER type;

-- 2. Update 'Doctor' role to 'Dentist' in case any exist (safeguard)
-- Note: You might need to temporarily expand the ENUM if strict mode prevents this, 
-- but usually allowed if we modify the column definition directly below.
-- However, standard practice is to Modify the ENUM to include 'Dentist' first.

ALTER TABLE tbl_notifications 
MODIFY COLUMN target_role ENUM('Admin', 'Doctor', 'Receptionist', 'Dentist') NULL;

UPDATE tbl_notifications SET target_role = 'Dentist' WHERE target_role = 'Doctor';

-- 3. Finalize ENUM by removing 'Doctor'
ALTER TABLE tbl_notifications 
MODIFY COLUMN target_role ENUM('Admin', 'Dentist', 'Receptionist') NULL;
