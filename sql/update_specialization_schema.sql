-- Add specialization column to tbl_user table
-- This column will store the specialization for dentists (e.g., 'Orthodontics', 'Periodontics')

ALTER TABLE tbl_user 
ADD COLUMN specialization VARCHAR(100) NULL AFTER role;

-- Update the index to include specialization if needed for filtering
CREATE INDEX idx_specialization ON tbl_user(specialization);
