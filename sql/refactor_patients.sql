-- 1. Create Patient Accounts Table
CREATE TABLE IF NOT EXISTS tbl_patient_accounts (
    account_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    otp VARCHAR(10) DEFAULT NULL,
    otp_expiry DATETIME DEFAULT NULL,
    is_verified TINYINT(1) DEFAULT 0,
    is_temporary_password TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Modify tbl_patient
-- Note: If you receive a syntax error on 'IF NOT EXISTS', simply remove 'IF NOT EXISTS' and run the line. 
-- If the column already exists, you can ignore the 'Duplicate column' error.

-- Link to the new Account ID
ALTER TABLE tbl_patient ADD COLUMN IF NOT EXISTS account_id INT NULL;
ALTER TABLE tbl_patient ADD CONSTRAINT fk_patient_account FOREIGN KEY (account_id) REFERENCES tbl_patient_accounts(account_id) ON DELETE SET NULL;

-- Add Split Name Fields
ALTER TABLE tbl_patient ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) AFTER patient_id;
ALTER TABLE tbl_patient ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) AFTER first_name;
ALTER TABLE tbl_patient ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) AFTER middle_name;

-- Add Age Fields
ALTER TABLE tbl_patient ADD COLUMN IF NOT EXISTS age INT AFTER dob;
ALTER TABLE tbl_patient ADD COLUMN IF NOT EXISTS age_category VARCHAR(20) AFTER age;


-- 3. Create Dependencies Table
CREATE TABLE IF NOT EXISTS tbl_dependencies (
    dependency_id INT AUTO_INCREMENT PRIMARY KEY,
    guardian_account_id INT NOT NULL,
    sub_patient_id INT NOT NULL,
    relationship VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guardian_account_id) REFERENCES tbl_patient_accounts(account_id) ON DELETE CASCADE,
    FOREIGN KEY (sub_patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE
);
