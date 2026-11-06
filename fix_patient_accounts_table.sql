-- Create tbl_patient_accounts with CORRECT schema matching registration.php
-- This table structure matches what registration.php expects

DROP TABLE IF EXISTS tbl_patient_accounts;

CREATE TABLE tbl_patient_accounts (
    account_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NULL UNIQUE COMMENT 'Link to tbl_patient (NULL for accounts pending patient creation)',
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL COMMENT 'Hashed password',
    is_verified TINYINT(1) DEFAULT 0 COMMENT 'Email verification status',
    otp VARCHAR(6) NULL COMMENT 'One-time password for verification',
    otp_expiry DATETIME NULL COMMENT 'OTP expiration time',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    
    FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE,
    INDEX idx_username (username),
    INDEX idx_patient (patient_id)
);
