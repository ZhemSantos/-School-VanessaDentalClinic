-- ============================================
-- Database Migration V2: Multi-Service & Robust Linking
-- Run this in your InfinityFree phpMyAdmin "SQL" tab
-- ============================================

-- 1. Create the Link Table for Appointment <-> Service (Many-to-Many)
CREATE TABLE IF NOT EXISTS tbl_appointment_service (
    appointment_service_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    service_id INT NOT NULL,
    price_at_booking DECIMAL(10, 2) NOT NULL, -- Snapshot of price at time of booking
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (appointment_id) REFERENCES tbl_appointment(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES tbl_service(service_id) ON DELETE RESTRICT,
    
    INDEX idx_appointment (appointment_id),
    INDEX idx_service (service_id)
);

-- 2. Migrate existing data: Move service_id from tbl_appointment to new table
-- We check if the column exists first to avoid errors on re-runs, 
-- but SQL scripts in phpMyAdmin usually just error if column missing.
-- We assume this is the first run.

INSERT INTO tbl_appointment_service (appointment_id, service_id, price_at_booking)
SELECT 
    a.appointment_id, 
    a.service_id, 
    s.base_price 
FROM tbl_appointment a
JOIN tbl_service s ON a.service_id = s.service_id
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_appointment_service tas 
    WHERE tas.appointment_id = a.appointment_id
);

-- 3. Add appointment_id to Medical Records for robust linking
ALTER TABLE tbl_medical_record
ADD COLUMN appointment_id INT NULL AFTER patient_id,
ADD INDEX idx_appointment_id (appointment_id),
ADD CONSTRAINT fk_record_appointment 
    FOREIGN KEY (appointment_id) REFERENCES tbl_appointment(appointment_id) ON DELETE SET NULL;

-- 4. Attempt to link existing medical records to appointments based on that "fuzzy logic" we had
-- (Matching logic: Same Patient, Same Doctor, Same Service, Appointment Date == Treatment Date)
UPDATE tbl_medical_record r
JOIN tbl_appointment a ON 
    r.patient_id = a.patient_id 
    AND r.doctor_id = a.doctor_id 
    AND r.service_id = a.service_id 
    AND DATE(r.date_of_treatment) = a.appointment_date
SET r.appointment_id = a.appointment_id
WHERE r.appointment_id IS NULL;

-- 5. Add appointment_id to Invoice for consolidated billing
ALTER TABLE tbl_invoice
ADD COLUMN appointment_id INT NULL AFTER invoice_id,
ADD INDEX idx_invoice_appointment (appointment_id),
ADD CONSTRAINT fk_invoice_appointment
    FOREIGN KEY (appointment_id) REFERENCES tbl_appointment(appointment_id) ON DELETE RESTRICT;

-- 6. Link existing invoices via their medical record's appointment_id
UPDATE tbl_invoice i
JOIN tbl_medical_record r ON i.record_id = r.record_id
SET i.appointment_id = r.appointment_id
WHERE i.appointment_id IS NULL AND r.appointment_id IS NOT NULL;

-- 7. (Optional but recommended) Remove service_id from tbl_appointment 
-- NOTE: We usually keep it for a while as "Primary Service" or for backward compatibility 
-- until the code is fully updated.
-- For now, we will NOT drop it to prevent breaking the live site while we update PHP.
-- ALTER TABLE tbl_appointment DROP FOREIGN KEY ...;
-- ALTER TABLE tbl_appointment DROP COLUMN service_id;

-- 8. Make Invoice record_id nullable because an invoice might now cover 
-- an appointment with NO medical record yet (e.g. advance payment) 
-- or multiple records (though usually 1 visit = 1 record + multiple services)
ALTER TABLE tbl_invoice MODIFY record_id INT NULL;

-- 9. Add 'Cancelled' status to Invoices if not present (good practice)
ALTER TABLE tbl_invoice MODIFY payment_status ENUM('Pending', 'Paid', 'Failed', 'Cancelled') DEFAULT 'Pending';
