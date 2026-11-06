-- ============================================
-- ADD DISCOUNT CARD FIELDS TO tbl_patient
-- ============================================

ALTER TABLE tbl_patient 
ADD COLUMN discount_type ENUM('None', 'PWD', 'Senior') DEFAULT 'None' AFTER address,
ADD COLUMN discount_id_number VARCHAR(100) NULL AFTER discount_type,
ADD COLUMN discount_id_issuer VARCHAR(100) NULL AFTER discount_id_number,
ADD COLUMN discount_id_image_path VARCHAR(255) NULL AFTER discount_id_issuer,
ADD COLUMN discount_id_expiry DATE NULL AFTER discount_id_image_path,
ADD COLUMN discount_verified_by INT NULL AFTER discount_id_expiry,
ADD COLUMN discount_verified_at TIMESTAMP NULL AFTER discount_verified_by,
ADD CONSTRAINT fk_discount_verified_by FOREIGN KEY (discount_verified_by) REFERENCES tbl_user(user_id) ON DELETE SET NULL;

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Check if columns were added successfully
DESCRIBE tbl_patient;

-- Verify default values for existing patients
SELECT patient_id, first_name, last_name, discount_type 
FROM tbl_patient 
LIMIT 5;
