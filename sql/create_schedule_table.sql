-- Create Clinic Schedule Table
CREATE TABLE IF NOT EXISTS tbl_clinic_schedule (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL UNIQUE,
    start_time TIME NULL,
    end_time TIME NULL,
    is_active TINYINT(1) DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert Default Values
-- Mon-Sat: 9am - 6pm (18:00)
-- Sun: 9am - 4pm (16:00)
INSERT INTO tbl_clinic_schedule (day_of_week, start_time, end_time, is_active) VALUES
('Monday', '09:00:00', '18:00:00', 1),
('Tuesday', '09:00:00', '18:00:00', 1),
('Wednesday', '09:00:00', '18:00:00', 1),
('Thursday', '09:00:00', '18:00:00', 1),
('Friday', '09:00:00', '18:00:00', 1),
('Saturday', '09:00:00', '18:00:00', 1),
('Sunday', '09:00:00', '16:00:00', 1)
ON DUPLICATE KEY UPDATE 
start_time = VALUES(start_time), 
end_time = VALUES(end_time),
is_active = VALUES(is_active);
