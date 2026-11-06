-- Migration: Change 'Confirmed' status to 'Booked' in tbl_appointment
-- Description: Updates the status ENUM and all existing records

-- Step 1: Update existing records
UPDATE tbl_appointment SET status = 'Booked' WHERE status = 'Confirmed';

-- Step 2: Modify the ENUM to replace 'Confirmed' with 'Booked'
-- Note: MySQL doesn't support direct ENUM modification, so we need to use ALTER TABLE
ALTER TABLE tbl_appointment 
MODIFY COLUMN status ENUM('Pending', 'Booked', 'In-Procedure', 'Completed', 'No-Show', 'Cancelled') DEFAULT 'Pending';

