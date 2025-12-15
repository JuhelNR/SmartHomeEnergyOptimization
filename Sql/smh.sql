
CREATE TABLE readings (
                                 id INT AUTO_INCREMENT PRIMARY KEY,
                                 device_id VARCHAR(50) NOT NULL,
                                 temperature FLOAT,
                                 humidity FLOAT,
                                 reading_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                 status VARCHAR(20)
);

CREATE TABLE room_sensors (
                              id INT AUTO_INCREMENT PRIMARY KEY,
                              device_id VARCHAR(50) NOT NULL,
                              room VARCHAR(50) NOT NULL,
                              temperature FLOAT,
                              motion_detected BOOLEAN,
                              light_level INT,
                              timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                              INDEX idx_room (room),
                              INDEX idx_timestamp (timestamp),
                              INDEX idx_device (device_id)
);

-- Device status table (current state of all devices)
CREATE TABLE device_status (
                               id INT AUTO_INCREMENT PRIMARY KEY,
                               device_id VARCHAR(50) NOT NULL,
                               room VARCHAR(50) NOT NULL,
                               device_type ENUM('light', 'fan') NOT NULL,
                               status BOOLEAN NOT NULL,
                               brightness INT DEFAULT NULL,
                               mode ENUM('auto', 'manual') DEFAULT 'auto',
                               last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                               UNIQUE KEY unique_device (device_id, room, device_type),
                               INDEX idx_room (room)
);

-- Manual control commands table (for remote control from dashboard)
CREATE TABLE control_commands (
                                  id INT AUTO_INCREMENT PRIMARY KEY,
                                  device_id VARCHAR(50) NOT NULL,
                                  room VARCHAR(50) NOT NULL,
                                  device_type ENUM('light', 'fan') NOT NULL,
                                  action ENUM('on', 'off', 'brightness') NOT NULL,
                                  value INT DEFAULT NULL,
                                  mode ENUM('auto', 'manual') NOT NULL,
                                  processed BOOLEAN DEFAULT FALSE,
                                  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                  INDEX idx_processed (processed),
                                  INDEX idx_device (device_id)
);

-- System warnings/alerts table
CREATE TABLE system_alerts (
                               id INT AUTO_INCREMENT PRIMARY KEY,
                               device_id VARCHAR(50) NOT NULL,
                               alert_type ENUM('high_temp', 'sensor_error', 'connection_lost', 'other') NOT NULL,
                               room VARCHAR(50),
                               message TEXT NOT NULL,
                               severity ENUM('info', 'warning', 'critical') DEFAULT 'warning',
                               resolved BOOLEAN DEFAULT FALSE,
                               created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                               resolved_at TIMESTAMP NULL,
                               INDEX idx_resolved (resolved),
                               INDEX idx_created (created_at),
                               INDEX idx_device (device_id)
);

-- System configuration table
CREATE TABLE system_config (
                               id INT AUTO_INCREMENT PRIMARY KEY,
                               config_key VARCHAR(100) UNIQUE NOT NULL,
                               config_value VARCHAR(255) NOT NULL,
                               description TEXT,
                               last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default configuration values
INSERT INTO system_config (config_key, config_value, description) VALUES
                                                                      ('temp_threshold', '28', 'Temperature threshold for fan activation (Celsius)'),
                                                                      ('motion_timeout', '30', 'Time in seconds before light turns off after no motion'),
                                                                      ('ldr_threshold_low', '300', 'LDR value for minimum brightness'),
                                                                      ('ldr_threshold_high', '800', 'LDR value for maximum brightness'),
                                                                      ('update_interval', '5', 'Sensor reading update interval in seconds');