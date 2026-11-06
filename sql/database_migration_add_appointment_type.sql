-- ============================================
-- Migration: Add appointment_type to tbl_appointment
-- Date: 2024
-- Description: Adds appointment_type field to distinguish between scheduled appointments and walk-ins
-- ============================================

-- Add appointment_type column to tbl_appointment table
ALTER TABLE tbl_appointment 
ADD COLUMN appointment_type ENUM('Appointment', 'Walk-in') DEFAULT 'Appointment' 
AFTER time_slot;

-- Update existing records to have 'Appointment' as default (already set by DEFAULT, but explicit update for clarity)
UPDATE tbl_appointment 
SET appointment_type = 'Appointment' 
WHERE appointment_type IS NULL;

-- ============================================
-- Migration Complete
-- ============================================
-- This migration adds the appointment_type field to distinguish between:
-- - 'Appointment': Scheduled appointments with date and time
-- - 'Walk-in': Immediate service bookings
-- 
-- All existing appointments will be set to 'Appointment' by default
-- ============================================

