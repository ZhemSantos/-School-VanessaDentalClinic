-- Database Modifications for Employee Registration & Security

-- 1. Add OTP columns to tbl_user for password resets and verification
-- (Note: Registration verification uses Session currently, but this is future-proof)
ALTER TABLE tbl_user ADD COLUMN otp VARCHAR(6) NULL;
ALTER TABLE tbl_user ADD COLUMN otp_expiry DATETIME NULL;

-- 2. Ensure profile_picture column exists (used in registration)
ALTER TABLE tbl_user ADD COLUMN profile_picture VARCHAR(255) NULL;

-- 3. Ensure prc_license_number exists
ALTER TABLE tbl_user ADD COLUMN prc_license_number VARCHAR(50) NULL;

-- 4. Create a table for system logs (Suggestion)
CREATE TABLE IF NOT EXISTS tbl_system_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action_type VARCHAR(50),
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
