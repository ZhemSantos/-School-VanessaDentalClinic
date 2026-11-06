-- Migration: Add Treatment Plans Table
-- Description: Allows doctors to create and manage treatment plans for patients

CREATE TABLE IF NOT EXISTS tbl_treatment_plan (
    plan_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    plan_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('Draft', 'Active', 'Completed', 'Cancelled') DEFAULT 'Draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE RESTRICT,
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE RESTRICT,
    
    -- Indexes
    INDEX idx_patient_id (patient_id),
    INDEX idx_doctor_id (doctor_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

