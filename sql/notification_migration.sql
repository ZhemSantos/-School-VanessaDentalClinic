-- Create tbl_notifications table
CREATE TABLE IF NOT EXISTS tbl_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, 
    target_role ENUM('Admin', 'Doctor', 'Receptionist') NULL,
    type ENUM('Appointment', 'System', 'Inventory', 'Payment') NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) NULL, 
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES tbl_user(user_id) ON DELETE CASCADE
);

-- Add indexes for performance
CREATE INDEX idx_notif_user ON tbl_notifications(user_id, is_read);
CREATE INDEX idx_notif_role ON tbl_notifications(target_role, is_read);
