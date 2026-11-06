-- Add reset_token and reset_expires_at columns to tbl_user
-- Run this in your database manager (phpMyAdmin, etc.)

ALTER TABLE tbl_user 
ADD COLUMN reset_token VARCHAR(64) NULL AFTER status,
ADD COLUMN reset_expires_at DATETIME NULL AFTER reset_token;
