-- ====================================================================
-- SMART HOME ENERGY OPTIMIZATION SYSTEM - COMPLETE DATABASE
-- ====================================================================

-- Drop existing database if you want a fresh start
DROP DATABASE IF EXISTS smarthome;

-- Create database
CREATE DATABASE smarthome CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Use the database
USE smarthome;

-- ====================================================================
-- TABLE 1: ROOM SENSORS
-- Stores real-time sensor readings from all rooms
-- ====================================================================
CREATE TABLE room_sensors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(50) NOT NULL,
    room VARCHAR(50) NOT NULL,
    temperature DOUBLE DEFAULT 0,
    humidity DOUBLE DEFAULT 0,
    motion_detected TINYINT(1) DEFAULT 0,
    light_level INT DEFAULT 0,
    current_reading DOUBLE DEFAULT 0,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_room (device_id, room),
    INDEX idx_timestamp (timestamp),
    INDEX idx_room (room)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- TABLE 2: DEVICE STATUS
-- Tracks the current state of all devices (lights, fans)
-- ====================================================================
CREATE TABLE device_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(50) NOT NULL,
    room VARCHAR(50) NOT NULL,
    device_type VARCHAR(20) NOT NULL,
    status TINYINT(1) DEFAULT 0,
    brightness INT DEFAULT 0,
    mode VARCHAR(10) DEFAULT 'auto',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_device (device_id, room, device_type),
    INDEX idx_room_device (room, device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- TABLE 3: SYSTEM ALERTS
-- Stores all system alerts and notifications
-- ====================================================================
CREATE TABLE system_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(50) NOT NULL,
    alert_type VARCHAR(50) NOT NULL,
    room VARCHAR(50),
    message TEXT,
    severity VARCHAR(20) DEFAULT 'warning',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_severity (severity),
    INDEX idx_room (room)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- TABLE 4: CONTROL COMMANDS
-- Queues commands to be sent to ESP32
-- ====================================================================
CREATE TABLE control_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room VARCHAR(50) NOT NULL,
    device_type VARCHAR(20) NOT NULL,
    action VARCHAR(20) NOT NULL,
    value INT DEFAULT 255,
    mode VARCHAR(10) DEFAULT 'manual',
    processed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_processed (processed),
    INDEX idx_created (created_at),
    INDEX idx_room_device (room, device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- TABLE 5: SYSTEM STATUS
-- Tracks overall system on/off state
-- ====================================================================
CREATE TABLE system_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(50) UNIQUE NOT NULL,
    status BOOLEAN NOT NULL,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_device (device_id),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- TABLE 6: READINGS (Compatibility table)
-- Legacy table for backward compatibility
-- ====================================================================
CREATE TABLE readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(50) NOT NULL,
    temperature DOUBLE DEFAULT 0,
    humidity DOUBLE DEFAULT 0,
    status VARCHAR(20) DEFAULT 'OK',
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device (device_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- TABLE 7: SYSTEM CONFIGURATION
-- Stores system settings loaded by ESP32
-- ====================================================================
CREATE TABLE system_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(50) UNIQUE NOT NULL,
    config_value VARCHAR(255) NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- TABLE 8: KEYPAD EVENTS
-- Tracks physical keypad button presses
-- ====================================================================
CREATE TABLE keypad_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(50) NOT NULL,
    key_pressed VARCHAR(10) NOT NULL,
    action VARCHAR(20) NOT NULL,
    success BOOLEAN DEFAULT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_timestamp (device_id, timestamp),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- INSERT DEFAULT CONFIGURATION VALUES
-- ====================================================================
INSERT INTO system_config (config_key, config_value, description) VALUES
('temp_threshold', '25.0', 'Temperature threshold for fan activation (Celsius)'),
('motion_timeout', '120', 'Motion timeout duration (seconds)'),
('ldr_threshold_low', '0', 'LDR low threshold for brightness calculation'),
('ldr_threshold_high', '2500', 'LDR high threshold (darkness level)'),
('device_timeout', '300', 'Device offline timeout (seconds)'),
('alert_retention_days', '30', 'Days to keep resolved alerts');

-- ====================================================================
-- INSERT INITIAL SYSTEM STATUS
-- ====================================================================
INSERT INTO system_status (device_id, status) VALUES
('ESP32_MAIN_001', FALSE);

-- ====================================================================
-- CREATE VIEWS FOR EASY QUERYING
-- ====================================================================

-- Latest sensor data per room
CREATE OR REPLACE VIEW v_latest_sensors AS
SELECT 
    rs.room,
    rs.temperature,
    rs.humidity,
    rs.motion_detected,
    rs.light_level,
    rs.current_reading,
    rs.timestamp,
    dl.status as light_status,
    dl.brightness,
    df.status as fan_status
FROM room_sensors rs
INNER JOIN (
    SELECT room, MAX(id) as max_id
    FROM room_sensors
    GROUP BY room
) latest ON rs.room = latest.room AND rs.id = latest.max_id
LEFT JOIN device_status dl ON rs.room = dl.room AND dl.device_type = 'light'
LEFT JOIN device_status df ON rs.room = df.room AND df.device_type = 'fan';

-- Active alerts
CREATE OR REPLACE VIEW v_active_alerts AS
SELECT 
    alert_type,
    room,
    message,
    severity,
    created_at
FROM system_alerts
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;

-- Pending commands
CREATE OR REPLACE VIEW v_pending_commands AS
SELECT 
    id,
    room,
    device_type,
    action,
    value,
    mode,
    created_at
FROM control_commands
WHERE processed = FALSE
ORDER BY created_at ASC;

-- ====================================================================
-- CREATE STORED PROCEDURES
-- ====================================================================

-- Clean old sensor data (keep last 7 days)
DELIMITER $$
CREATE PROCEDURE sp_cleanup_old_data()
BEGIN
    DELETE FROM room_sensors WHERE timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY);
    DELETE FROM keypad_events WHERE timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- Get retention days from config
    DECLARE retention_days INT;
    SELECT config_value INTO retention_days FROM system_config WHERE config_key = 'alert_retention_days';
    
    DELETE FROM system_alerts WHERE created_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
END$$
DELIMITER ;

-- Get room statistics
DELIMITER $$
CREATE PROCEDURE sp_get_room_stats(IN room_name VARCHAR(50))
BEGIN
    SELECT 
        AVG(temperature) as avg_temp,
        AVG(humidity) as avg_humidity,
        AVG(light_level) as avg_light,
        AVG(current_reading) as avg_current,
        COUNT(*) as total_readings,
        SUM(motion_detected) as motion_count
    FROM room_sensors
    WHERE room = room_name 
    AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
END$$
DELIMITER ;

-- ====================================================================
-- CREATE EVENTS FOR AUTOMATIC CLEANUP
-- ====================================================================

-- Enable event scheduler
SET GLOBAL event_scheduler = ON;

-- Daily cleanup at 3 AM
CREATE EVENT IF NOT EXISTS evt_daily_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE() + INTERVAL 1 DAY, ' 03:00:00')
DO
    CALL sp_cleanup_old_data();

-- ====================================================================
-- GRANT PERMISSIONS (Update username/password as needed)
-- ====================================================================
-- GRANT ALL PRIVILEGES ON smarthome.* TO 'your_username'@'localhost';
-- FLUSH PRIVILEGES;

-- ====================================================================
-- VERIFICATION QUERIES
-- ====================================================================

-- Show all tables
SHOW TABLES;

-- Show table structures
SHOW CREATE TABLE room_sensors;
SHOW CREATE TABLE device_status;
SHOW CREATE TABLE control_commands;
SHOW CREATE TABLE system_config;

-- Show initial data
SELECT * FROM system_config;
SELECT * FROM system_status;

-- ====================================================================
-- END OF SCRIPT
-- ====================================================================

SELECT 'Database created successfully!' as Status;
