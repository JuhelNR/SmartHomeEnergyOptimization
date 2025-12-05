CREATE TABLE readings (
                                 id INT AUTO_INCREMENT PRIMARY KEY,
                                 device_id VARCHAR(50) NOT NULL,
                                 temperature FLOAT,
                                 humidity FLOAT,
                                 reading_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                 status VARCHAR(20)
);
