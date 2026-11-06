-- Migration: Add last_active column to tbl_user for real-time activity tracking
-- Run this SQL query in your database to enable real-time user activity tracking

ALTER TABLE tbl_user 
ADD COLUMN last_active TIMESTAMP NULL DEFAULT NULL AFTER created_at;

-- Create index for faster queries on last_active
CREATE INDEX idx_last_active ON tbl_user(last_active);

-- Update existing users to have current timestamp as initial last_active
UPDATE tbl_user SET last_active = NOW() WHERE last_active IS NULL;

