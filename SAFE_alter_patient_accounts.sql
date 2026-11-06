-- SAFE Migration for tbl_patient_accounts
-- This adds missing columns WITHOUT dropping the table
-- Run this to fix all issues while preserving existing data

-- Add missing columns (IF NOT EXISTS prevents errors if column already exists)
ALTER TABLE tbl_patient_accounts ADD COLUMN IF NOT EXISTS patient_id INT NULL UNIQUE COMMENT 'Link to tbl_patient';
ALTER TABLE tbl_patient_accounts ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) DEFAULT 0 COMMENT 'Email verification status';
ALTER TABLE tbl_patient_accounts ADD COLUMN IF NOT EXISTS is_temporary_password TINYINT(1) DEFAULT 0 COMMENT 'Temporary password flag';
ALTER TABLE tbl_patient_accounts ADD COLUMN IF NOT EXISTS otp VARCHAR(6) NULL COMMENT 'One-time password for verification';
ALTER TABLE tbl_patient_accounts ADD COLUMN IF NOT EXISTS otp_expiry DATETIME NULL COMMENT 'OTP expiration time';
ALTER TABLE tbl_patient_accounts ADD COLUMN IF NOT EXISTS last_active TIMESTAMP NULL COMMENT 'Last activity timestamp';
ALTER TABLE tbl_patient_accounts ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL COMMENT 'Last login timestamp';

-- If you have 'password' column instead of 'password_hash', uncomment this line:
-- ALTER TABLE tbl_patient_accounts CHANGE password password_hash VARCHAR(255) NOT NULL;

-- Add foreign key constraint (will fail silently if already exists)
ALTER TABLE tbl_patient_accounts 
ADD CONSTRAINT fk_patient_account_patient 
FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE;

-- Add indexes for performance
CREATE INDEX IF NOT EXISTS idx_username ON tbl_patient_accounts(username);
CREATE INDEX IF NOT EXISTS idx_patient ON tbl_patient_accounts(patient_id);

-- Verify the table structure
DESCRIBE tbl_patient_accounts;
