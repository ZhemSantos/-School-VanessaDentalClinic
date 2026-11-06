-- Add is_password_temporary column to tbl_patient_accounts
ALTER TABLE tbl_patient_accounts
ADD COLUMN is_password_temporary TINYINT(1) DEFAULT 0 AFTER is_verified;

-- Optional: Update existing patients to have temporary password if needed (default is 0)
-- UPDATE tbl_patient_accounts SET is_password_temporary = 1 WHERE ...;
