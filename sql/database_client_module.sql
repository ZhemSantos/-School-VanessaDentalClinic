-- ============================================
-- Client Module Database Updates
-- ============================================

-- 1. Create Parent Consent Form Table
CREATE TABLE IF NOT EXISTS tbl_parent_consent (
    consent_id INT AUTO_INCREMENT PRIMARY KEY,
    guardian_patient_id INT NOT NULL,
    dependent_patient_id INT NOT NULL,
    consent_date DATE NOT NULL,
    guardian_signature VARCHAR(255) NULL, -- Path to signature image or digital sign
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guardian_patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (dependent_patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE,
    INDEX idx_guardian (guardian_patient_id),
    INDEX idx_dependent (dependent_patient_id)
);

-- 2. Update Feedback Table for Multi-Section Ratings
-- Adding specific columns for breakdown, keeping 'rating' as legacy or overall fallback if needed
-- We'll also add an overall_rating column for the calculated average
ALTER TABLE tbl_feedback 
ADD COLUMN IF NOT EXISTS booking_rating INT CHECK (booking_rating BETWEEN 1 AND 5),
ADD COLUMN IF NOT EXISTS procedure_rating INT CHECK (procedure_rating BETWEEN 1 AND 5),
ADD COLUMN IF NOT EXISTS payment_rating INT CHECK (payment_rating BETWEEN 1 AND 5),
ADD COLUMN IF NOT EXISTS overall_rating DECIMAL(3,2);

-- 3. Update Services with Prerequisites
ALTER TABLE tbl_service
ADD COLUMN IF NOT EXISTS requires_checkup TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS requires_xray TINYINT(1) DEFAULT 0;

-- 4. Apply Service Prerequisites based on Categories
-- General Dentistry = CR (Optional -> 0), XRR (Optional -> 0)
UPDATE tbl_service SET requires_checkup = 0, requires_xray = 0 WHERE category = 'General Dentistry';

-- Orthodontics = CR, XRR (Both Required)
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 1 WHERE category = 'Orthodontics';

-- Pediatric Dentistry (Pedodontics) = CR, XRR (Optional)
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 0 WHERE category = 'Pediatric Dentistry' OR category = 'Pedodontics';

-- Periodontics = CR, XRR
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 1 WHERE category = 'Periodontics';

-- Prosthodontics = CR, XRR
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 1 WHERE category = 'Prosthodontics';

-- Endodontics = CR, XRR
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 1 WHERE category = 'Endodontics';

-- Oral and Maxillofacial Surgery = CR, XRR
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 1 WHERE category = 'Oral and Maxillofacial Surgery';

-- Oral and Maxillofacial Pathology = CR, XRR (Optional)
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 0 WHERE category = 'Oral and Maxillofacial Pathology';

-- Oral and Maxillofacial Radiology = CR (No), XRR (No)
UPDATE tbl_service SET requires_checkup = 0, requires_xray = 0 WHERE category = 'Oral and Maxillofacial Radiology';

-- Dental Anesthesiology = CR, XRR (No)
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 0 WHERE category = 'Dental Anesthesiology';

-- Dental Public Health = CR (No), XRR (No)
UPDATE tbl_service SET requires_checkup = 0, requires_xray = 0 WHERE category = 'Dental Public Health';

-- Oral Medicine = CR, XRR (Optional)
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 0 WHERE category = 'Oral Medicine';

-- Special Needs Dentistry = CR, XRR (Optional)
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 0 WHERE category = 'Special Needs Dentistry';

-- Implantology = CR, XRR
UPDATE tbl_service SET requires_checkup = 1, requires_xray = 1 WHERE category = 'Implantology';
