-- ============================================
-- Migration: Add tbl_blocked_time table
-- Date: 2024
-- Description: Creates a table for storing doctor's blocked time slots
-- ============================================

CREATE TABLE IF NOT EXISTS tbl_blocked_time (
    block_id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    block_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign Keys
    FOREIGN KEY (doctor_id) REFERENCES tbl_user(user_id) ON DELETE CASCADE,
    
    -- Indexes for query performance
    INDEX idx_doctor_date (doctor_id, block_date),
    INDEX idx_date (block_date),
    INDEX idx_doctor_date_time (doctor_id, block_date, start_time, end_time)
);

-- ============================================
-- Migration Complete
-- ============================================
-- This table stores blocked time slots for doctors
-- Blocked time prevents booking appointments during these periods
-- ============================================
