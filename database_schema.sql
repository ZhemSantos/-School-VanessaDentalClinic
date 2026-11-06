-- ============================================
-- Vanessa Nicolas Dental Clinic Database Schema
-- FINALIZED VERSION - Complete Database Structure
-- For InfinityFree Hosting
-- ============================================

-- 1. Create the Database
-- Note: Database is created via InfinityFree control panel
-- Database Name: if0_40717152_vanessaclinic

-- ============================================
-- 2. USERS & ACCOUNTS
-- ============================================

-- 2.1 USER TABLE (Staff: Admin, Dentists, Receptionists, Dental Assistants)
CREATE TABLE IF NOT EXISTS tbl_user (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    prc_license_number VARCHAR(50) NULL, -- For Dentists
    blood_type ENUM('A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-', 'Unknown') NOT NULL DEFAULT 'Unknown',
    contact_number VARCHAR(15) NULL,
    email VARCHAR(100) NULL,
    profile_picture VARCHAR(255) NULL,
    role ENUM('Admin', 'Dentist', 'Receptionist', 'Dental Assistant') NOT NULL,
    specialization VARCHAR(100) NULL, -- For Dentists
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    is_password_temporary TINYINT(1) DEFAULT 0,
    reset_token VARCHAR(64) NULL,
    reset_expiry DATETIME NULL,
    otp VARCHAR(6) NULL,
    otp_expiry DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active TIMESTAMP NULL DEFAULT NULL,
    
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_status (status)
);

-- 2.2 PATIENT ACCOUNTS TABLE (Client Portal Access)
CREATE TABLE IF NOT EXISTS tbl_patient_accounts (
    account_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    otp VARCHAR(6) NULL,
    otp_expiry DATETIME NULL,
    is_verified TINYINT(1) DEFAULT 0,
    is_temporary_password TINYINT(1) DEFAULT 0,
    last_active TIMESTAMP NULL,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_username (username)
);

-- ============================================
-- 3. SERVICES & CONSENTS
-- ============================================

-- 3.1 SERVICES TABLE
CREATE TABLE IF NOT EXISTS tbl_service (
    service_id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NULL,
    base_price DECIMAL(10, 2) NOT NULL,
    estimated_duration_mins INT NOT NULL,
    is_installment_available TINYINT(1) DEFAULT 0,
    installment_down_payment DECIMAL(10,2) DEFAULT 0.00,
    installment_duration_months INT DEFAULT 0,
    installment_interest_percent DECIMAL(5,2) DEFAULT 0.00,
    installment_monthly_payment DECIMAL(10,2) DEFAULT 0.00,
    requires_checkup TINYINT(1) DEFAULT 0,
    requires_xray TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_category (category),
    INDEX idx_service_name (service_name)
);

-- 3.2 CONSENT REQUIREMENTS (Rules by Category)
CREATE TABLE IF NOT EXISTS tbl_consent_requirement (
    requirement_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    requires_gncf TINYINT(1) DEFAULT 0, -- General Consent Form
    requires_sscf TINYINT(1) DEFAULT 0, -- Specific Service Consent Form
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category_name)
);

-- ============================================
-- 4. PATIENTS & FAMILIES
-- ============================================

-- 4.1 PATIENTS TABLE
CREATE TABLE IF NOT EXISTS tbl_patient (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NULL, -- Link to Portal Account
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    dob DATE NOT NULL,
    age INT NULL,
    age_category VARCHAR(20) NULL, -- Child, Adult, Senior
    gender ENUM('Male', 'Female', 'Other') NULL,
    phone_number VARCHAR(15) NOT NULL,
    email VARCHAR(100) NULL,
    medical_history TEXT NULL,
    blood_type ENUM('A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-', 'Unknown') NOT NULL DEFAULT 'Unknown',
    civil_status ENUM('Single', 'Married', 'Widowed', 'Separated') NULL,
    occupation VARCHAR(50) NULL,
    address TEXT NULL,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (account_id) REFERENCES tbl_patient_accounts(account_id) ON DELETE SET NULL,
    
    INDEX idx_name (first_name, last_name),
    INDEX idx_phone (phone_number),
    INDEX idx_email (email),
    INDEX idx_account (account_id)
);

-- 4.2 DEPENDENCIES TABLE (Guardian-Dependent Relationships)
CREATE TABLE IF NOT EXISTS tbl_dependencies (
    dependency_id INT AUTO_INCREMENT PRIMARY KEY,
    guardian_account_id INT NOT NULL,
    sub_patient_id INT NOT NULL,
    relationship VARCHAR(50),
    access_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(64) NULL,
    token_expires DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (guardian_account_id) REFERENCES tbl_patient_accounts(account_id) ON DELETE CASCADE,
    FOREIGN KEY (sub_patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE,
    
    INDEX idx_verification (verification_token)
);

-- 4.3 PARENT CONSENT FORMS
CREATE TABLE IF NOT EXISTS tbl_parent_consent (
    consent_id INT AUTO_INCREMENT PRIMARY KEY,
    guardian_patient_id INT NOT NULL,
    dependent_patient_id INT NOT NULL,
    consent_date DATE NOT NULL,
    guardian_signature VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (guardian_patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (dependent_patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE
);

-- ============================================
-- 5. SCHEDULING
-- ============================================

-- 5.1 CLINIC SCHEDULE (Operating Hours)
CREATE TABLE IF NOT EXISTS tbl_clinic_schedule (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL UNIQUE,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_open TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5.2 DOCTOR SCHEDULE (Availability)
CREATE TABLE IF NOT EXISTS tbl_doctor_schedule (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_doctor_day (doctor_id, day_of_week)
);

-- 5.3 BLOCKED TIME
CREATE TABLE IF NOT EXISTS tbl_blocked_time (
    block_id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    block_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE CASCADE,
    INDEX idx_doctor_block (doctor_id, block_date)
);

-- 5.4 APPOINTMENTS
CREATE TABLE IF NOT EXISTS tbl_appointment (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    appointment_date DATE NOT NULL,
    time_slot TIME NOT NULL,
    appointment_type ENUM('Appointment', 'Walk-in') DEFAULT 'Appointment',
    status ENUM('Pending', 'Booked', 'Arrived', 'In-Procedure', 'Completed', 'No-Show', 'Cancelled') DEFAULT 'Pending',
    appointment_fee_paid DECIMAL(10, 2) DEFAULT 0.00,
    appointment_fee_invoice_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE RESTRICT,
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE RESTRICT,
    
    INDEX idx_date (appointment_date),
    INDEX idx_doctor_date (doctor_id, appointment_date)
);

-- 5.5 APPOINTMENT SERVICES
CREATE TABLE IF NOT EXISTS tbl_appointment_service (
    appointment_service_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    service_id INT NOT NULL,
    price_at_booking DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (appointment_id) REFERENCES tbl_appointment(appointment_id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES tbl_service(service_id) ON DELETE RESTRICT
);

-- ============================================
-- 6. MEDICAL RECORDS & FILES
-- ============================================

-- 6.1 TREATMENT PLANS
CREATE TABLE IF NOT EXISTS tbl_treatment_plan (
    plan_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    plan_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('Draft', 'Active', 'Completed', 'Cancelled') DEFAULT 'Draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE RESTRICT,
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE RESTRICT
);

-- 6.2 MEDICAL RECORDS
CREATE TABLE IF NOT EXISTS tbl_medical_record (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    appointment_id INT NULL,
    doctor_id INT NOT NULL,
    service_id INT NOT NULL,
    date_of_treatment DATETIME DEFAULT CURRENT_TIMESTAMP,
    diagnosis TEXT NOT NULL,
    treatment_notes TEXT NULL,
    tooth_numbers VARCHAR(50) NULL,
    file_attachment VARCHAR(255) NULL,
    
    FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE RESTRICT,
    FOREIGN KEY (appointment_id) REFERENCES tbl_appointment(appointment_id) ON DELETE SET NULL,
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE RESTRICT,
    FOREIGN KEY (service_id) REFERENCES tbl_service(service_id) ON DELETE RESTRICT
);

-- 6.3 PATIENT FILES (History, X-rays, Consents)
CREATE TABLE IF NOT EXISTS tbl_patient_file (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NULL,
    file_size INT NULL,
    description TEXT NULL,
    consent_type ENUM('GNCF', 'SSCF', 'None') DEFAULT 'None',
    consent_category VARCHAR(100) NULL,
    is_signed TINYINT(1) DEFAULT 0,
    expiration_date DATE NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE RESTRICT
);

-- ============================================
-- 7. BILLING & PAYMENTS
-- ============================================

-- 7.1 INVOICES
CREATE TABLE IF NOT EXISTS tbl_invoice (
    invoice_id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NULL,
    record_id INT NULL,
    reference_number VARCHAR(50) UNIQUE,
    total_amount DECIMAL(10, 2) NOT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    vat_exempt_amount DECIMAL(10,2) DEFAULT 0.00,
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    payment_method ENUM('Cash', 'GCash', 'Card') NOT NULL,
    payment_reference VARCHAR(100) NULL,
    payment_status ENUM('Pending', 'Paid', 'Failed', 'Cancelled') DEFAULT 'Pending',
    installment_id INT NULL, -- Link if payment is for an installment
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    
    FOREIGN KEY (appointment_id) REFERENCES tbl_appointment(appointment_id) ON DELETE RESTRICT,
    FOREIGN KEY (record_id) REFERENCES tbl_medical_record(record_id) ON DELETE RESTRICT,
    
    INDEX idx_reference (reference_number)
);

-- 7.2 INVOICE DISCOUNTS
CREATE TABLE IF NOT EXISTS tbl_invoice_discount (
    discount_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NULL,
    discount_type ENUM('None', 'PWD', 'Senior') NOT NULL DEFAULT 'None',
    id_number VARCHAR(100) NULL,
    id_issuer VARCHAR(100) NULL,
    id_image_path VARCHAR(255) NULL,
    verified_by INT NULL,
    date_verified TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (verified_by) REFERENCES tbl_user(user_id) ON DELETE SET NULL,
    FOREIGN KEY (invoice_id) REFERENCES tbl_invoice(invoice_id) ON DELETE CASCADE
);

-- 7.3 INSTALLMENT PLANS
CREATE TABLE IF NOT EXISTS tbl_installment (
    installment_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    service_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    down_payment_paid DECIMAL(10,2) DEFAULT 0.00,
    balance_remaining DECIMAL(10,2) NOT NULL,
    monthly_amount DECIMAL(10,2) NOT NULL,
    months_total INT NOT NULL,
    months_paid INT DEFAULT 0,
    penalty_accumulated DECIMAL(10,2) DEFAULT 0.00,
    penalty_paid DECIMAL(10,2) DEFAULT 0.00,
    start_date DATE NOT NULL,
    next_payment_date DATE NOT NULL,
    status ENUM('Active', 'Completed', 'Defaulted') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id) ON DELETE RESTRICT,
    FOREIGN KEY (service_id) REFERENCES tbl_service(service_id) ON DELETE RESTRICT
);

-- ============================================
-- 8. SYSTEM & FEEDBACK
-- ============================================

-- 8.1 FEEDBACK
CREATE TABLE IF NOT EXISTS tbl_feedback (
    feedback_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5), -- Overall legacy rating
    booking_rating INT CHECK (booking_rating BETWEEN 1 AND 5),
    procedure_rating INT CHECK (procedure_rating BETWEEN 1 AND 5),
    payment_rating INT CHECK (payment_rating BETWEEN 1 AND 5),
    overall_rating DECIMAL(3,2),
    comments TEXT NULL,
    date_submitted TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (invoice_id) REFERENCES tbl_invoice(invoice_id) ON DELETE CASCADE
);

-- 8.2 NOTIFICATIONS
CREATE TABLE IF NOT EXISTS tbl_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    target_role ENUM('Admin', 'Dentist', 'Receptionist') NULL,
    type ENUM('Appointment', 'System', 'Inventory', 'Payment') NOT NULL,
    title VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES tbl_user(user_id) ON DELETE CASCADE
);

-- 8.3 SYSTEM LOGS
CREATE TABLE IF NOT EXISTS tbl_system_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action_type VARCHAR(50),
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 8.4 SETTINGS
CREATE TABLE IF NOT EXISTS tbl_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
