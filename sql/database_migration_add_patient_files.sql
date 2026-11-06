-- Migration: Add Patient Files Table
-- Description: Allows doctors to upload and manage multiple files (X-rays, documents, etc.) for patients

CREATE TABLE IF NOT EXISTS tbl_patient_file (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NULL, -- e.g., 'xray', 'document', 'photo', 'other'
    file_size INT NULL, -- Size in bytes
    description TEXT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE RESTRICT,
    
    -- Indexes
    INDEX idx_patient_id (patient_id),
    INDEX idx_doctor_id (doctor_id),
    INDEX idx_file_type (file_type),
    INDEX idx_uploaded_at (uploaded_at)
);

