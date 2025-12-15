<?php
require "../vendor/autoload.php";

//ERROR LOG FILE PATH CONFIG
$logDir = "../monitoring";
$logFile = $logDir . "/api_ingest.log";

//CREATE DIRECTORY IF IT DOESN'T EXIST
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

//HELPER FUNCTION TO LOG MESSAGES INTO FILE
function logError($message) {
    global $logFile;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}


$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

logError("INGEST REQUEST: Method=$method, Action=$action");

// Route ESP32 requests
try {
    switch($action) {
        case 'update_sensors':
            if ($method === 'POST') {
                updateSensorData();
            } else {
                logError("Invalid method for update_sensors: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'update_device_status':
            if ($method === 'POST') {
                updateDeviceStatus();
            } else {
                logError("Invalid method for update_device_status: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'get_commands':
            if ($method === 'GET') {
                getControlCommands();
            } else {
                logError("Invalid method for get_commands: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'add_alert':
            if ($method === 'POST') {
                addAlert();
            } else {
                logError("Invalid method for add_alert: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'get_config':
            if ($method === 'GET') {
                getConfig();
            } else {
                logError("Invalid method for get_config: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'update_system_status':
            if ($method === 'POST') {
                updateSystemStatus();
            } else {
                logError("Invalid method for update_system_status: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        default:
            logError("Invalid action requested: $action");
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    logError("EXCEPTION: " . $e->getMessage());
    echo json_encode(['error' => 'Server error occurred: ' . $e->getMessage()]);
}

// ==================== FUNCTION DEFINITIONS ====================

// ESP32 sends sensor data
function updateSensorData() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        logError("ERROR: Invalid JSON data for update_sensors");
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    logError("Received sensor data: " . json_encode($data));

    try {
        $device_id = $data['device_id'] ?? '';
        $room = $data['room'] ?? '';
        $temperature = $data['temperature'] ?? 0;
        $motion = $data['motion_detected'] ? 1 : 0;
        $light = $data['light_level'] ?? 0;

        // Insert into NEW room_sensors table
        $query1 = "INSERT INTO room_sensors (device_id, room, temperature, motion_detected, light_level) 
                   VALUES (?, ?, ?, ?, ?)";
        conn::executeQuery($query1, [$device_id, $room, $temperature, $motion, $light]);

        // ALSO insert into your EXISTING readings table for compatibility
        $status = "OK";
        $query2 = "INSERT INTO readings (device_id, temperature, humidity, status) 
                   VALUES (?, ?, ?, ?)";
        conn::executeQuery($query2, [$device_id, $temperature, $temperature, $status]);

        logError("SUCCESS: Sensor data inserted for room " . $room);
        echo json_encode(['success' => true, 'message' => 'Sensor data updated']);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to insert sensor data - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// ESP32 updates device status
function updateDeviceStatus() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        logError("ERROR: Invalid JSON data for update_device_status");
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    try {
        $device_id = $data['device_id'] ?? '';
        $room = $data['room'] ?? '';
        $device_type = $data['device_type'] ?? '';
        $status = $data['status'] ? 1 : 0;
        $brightness = $data['brightness'] ?? null;
        $mode = $data['mode'] ?? 'auto';

        $query = "INSERT INTO device_status (device_id, room, device_type, status, brightness, mode) 
                  VALUES (?, ?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE 
                      status = ?, 
                      brightness = ?,
                      mode = ?,
                      last_updated = CURRENT_TIMESTAMP";

        conn::executeQuery($query, [
            $device_id, $room, $device_type, $status, $brightness, $mode,
            $status, $brightness, $mode
        ]);

        logError("SUCCESS: Device status updated - " . $room . " " . $device_type);
        echo json_encode(['success' => true]);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to update device status - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// ESP32 checks for pending control commands from dashboard
function getControlCommands() {
    try {
        $query = "SELECT * FROM control_commands WHERE processed = FALSE ORDER BY created_at ASC";
        $result = conn::executeQuery($query);

        $commands = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $commands[] = $row;
        }

        logError("Retrieved " . count($commands) . " pending commands");

        // Mark commands as processed
        if (!empty($commands)) {
            $ids = array_column($commands, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $updateQuery = "UPDATE control_commands SET processed = TRUE WHERE id IN ($placeholders)";
            conn::executeQuery($updateQuery, $ids);

            logError("Marked " . count($ids) . " commands as processed");
        }

        echo json_encode(['commands' => $commands]);
        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to get commands - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// ESP32 sends alert
function addAlert() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        logError("ERROR: Invalid JSON data for add_alert");
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    logError("Alert received: " . $data['message']);

    try {
        $device_id = $data['device_id'] ?? '';
        $alert_type = $data['alert_type'] ?? '';
        $room = $data['room'] ?? null;
        $message = $data['message'] ?? '';
        $severity = $data['severity'] ?? 'warning';

        $query = "INSERT INTO system_alerts (device_id, alert_type, room, message, severity) 
                  VALUES (?, ?, ?, ?, ?)";

        conn::executeQuery($query, [$device_id, $alert_type, $room, $message, $severity]);

        logError("SUCCESS: Alert logged - " . $alert_type);
        echo json_encode(['success' => true]);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to add alert - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// ESP32 gets system configuration on bootup
function getConfig() {
    try {
        $query = "SELECT * FROM system_config";
        $result = conn::executeQuery($query);

        $configArray = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $configArray[$row['config_key']] = $row['config_value'];
        }

        logError("SUCCESS: Configuration retrieved");
        echo json_encode(['config' => $configArray]);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to get config - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// ESP32 sends system status
function updateSystemStatus() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        logError("ERROR: Invalid JSON data for update_system_status");
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    $deviceID = $data['device_id'] ?? '';
    $status = $data['status'] ?? 0;

    logError("System status update: Device=$deviceID, Status=$status");

    try {
        // Check if table exists, if not create it
        $createTableQuery = "CREATE TABLE IF NOT EXISTS system_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(50) UNIQUE NOT NULL,
            status BOOLEAN NOT NULL,
            last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        conn::executeQuery($createTableQuery);

        $query = "INSERT INTO system_status (device_id, status, last_seen) 
                  VALUES (?, ?, NOW())
                  ON DUPLICATE KEY UPDATE status = ?, last_seen = NOW()";

        conn::executeQuery($query, [$deviceID, $status, $status]);

        logError("SUCCESS: System status updated for device $deviceID");
        echo json_encode(['success' => true]);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to update system status - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>