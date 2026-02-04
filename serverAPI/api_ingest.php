<?php
require_once "../config/connect.php";

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

        case 'keypad_event':
            if ($method === 'POST') {
                logKeypadEvent();
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
    global $conn;

    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        logError("ERROR: Invalid JSON data for update_sensors");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    logError("Received sensor data: " . json_encode($data));

    $device_id   = $data['device_id'] ?? '';
    $room        = $data['room'] ?? '';
    $temperature = $data['temperature'] ?? 0;
    $humidity    = $data['humidity'] ?? 0;
    $motion      = !empty($data['motion_detected']) ? 1 : 0;
    $light       = $data['light_level'] ?? 0;
    $current     = $data['current'] ?? 0;

    // Insert into room_sensors
    $sql = "INSERT INTO room_sensors 
        (device_id, room, temperature, humidity, motion_detected, light_level, current_reading)
        VALUES (?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        logError("ERROR: Prepare failed for room_sensors - " . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => $conn->error]);
        return;
    }

    $stmt->bind_param(
        "ssddidd",
        $device_id,
        $room,
        $temperature,
        $humidity,
        $motion,
        $light,
        $current
    );

    if (!$stmt->execute()) {
        logError("ERROR: Execute failed for room_sensors - " . $stmt->error);
        http_response_code(500);
        echo json_encode(['error' => $stmt->error]);
        $stmt->close();
        return;
    }

    logError("SUCCESS: Sensor data inserted for room $room (T:$temperature H:$humidity C:$current)");
    $stmt->close();

    // Also insert into readings table for compatibility
    $sql2 = "INSERT INTO readings (device_id, temperature, humidity, status) 
             VALUES (?, ?, ?, 'OK')";
    
    $stmt2 = $conn->prepare($sql2);
    if ($stmt2) {
        $stmt2->bind_param("sdd", $device_id, $temperature, $humidity);
        $stmt2->execute();
        $stmt2->close();
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Sensor data updated']);
}


// ESP32 updates device status
function updateDeviceStatus() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        logError("ERROR: Invalid JSON data for update_device_status");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    $device_id = $data['device_id'] ?? '';
    $room = $data['room'] ?? '';
    $device_type = $data['device_type'] ?? '';
    $status = $data['status'] ? 1 : 0;
    $brightness = $data['brightness'] ?? null;
    $mode = $data['mode'] ?? 'auto';

    $sql = "INSERT INTO device_status (device_id, room, device_type, status, brightness, mode) 
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                brightness = VALUES(brightness),
                mode = VALUES(mode),
                last_updated = CURRENT_TIMESTAMP";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        logError("ERROR: Prepare failed for device_status - " . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => $conn->error]);
        return;
    }

    $stmt->bind_param(
        "sssiis",
        $device_id,
        $room,
        $device_type,
        $status,
        $brightness,
        $mode
    );

    if (!$stmt->execute()) {
        logError("ERROR: Execute failed for device_status - " . $stmt->error);
        http_response_code(500);
        echo json_encode(['error' => $stmt->error]);
        $stmt->close();
        return;
    }

    logError("SUCCESS: Device status updated - $room $device_type (status:$status brightness:$brightness mode:$mode)");
    $stmt->close();

    http_response_code(200);
    echo json_encode(['success' => true]);
}

// ESP32 checks for pending control commands from dashboard
function getControlCommands() {
    global $conn;

    $sql = "SELECT * FROM control_commands WHERE processed = FALSE ORDER BY created_at ASC";
    $result = $conn->query($sql);

    if (!$result) {
        logError("ERROR: Query failed for control_commands - " . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => $conn->error]);
        return;
    }

    $commands = [];
    while ($row = $result->fetch_assoc()) {
        $commands[] = $row;
    }

    logError("Retrieved " . count($commands) . " pending commands");

    // Mark commands as processed
    if (!empty($commands)) {
        $ids = array_column($commands, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $updateSql = "UPDATE control_commands SET processed = TRUE WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($updateSql);
        
        if ($stmt) {
            $types = str_repeat('i', count($ids));
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $stmt->close();
            
            logError("Marked " . count($ids) . " commands as processed");
        }
    }

    http_response_code(200);
    echo json_encode(['commands' => $commands]);
}

// ESP32 sends alert
function addAlert() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        logError("ERROR: Invalid JSON data for add_alert");
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    logError("Alert received: " . ($data['message'] ?? 'No message'));

    $device_id = $data['device_id'] ?? '';
    $alert_type = $data['alert_type'] ?? '';
    $room = $data['room'] ?? null;
    $message = $data['message'] ?? '';
    $severity = $data['severity'] ?? 'warning';

    $sql = "INSERT INTO system_alerts (device_id, alert_type, room, message, severity) 
            VALUES (?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        logError("ERROR: Prepare failed for system_alerts - " . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => $conn->error]);
        return;
    }

    $stmt->bind_param(
        "sssss",
        $device_id,
        $alert_type,
        $room,
        $message,
        $severity
    );

    if (!$stmt->execute()) {
        logError("ERROR: Execute failed for system_alerts - " . $stmt->error);
        http_response_code(500);
        echo json_encode(['error' => $stmt->error]);
        $stmt->close();
        return;
    }

    logError("SUCCESS: Alert logged - $alert_type from $room (severity: $severity)");
    $stmt->close();

    http_response_code(200);
    echo json_encode(['success' => true]);
}

// ESP32 gets system configuration on bootup
function getConfig() {
    global $conn;

    $sql = "SELECT * FROM system_config";
    $result = $conn->query($sql);

    if (!$result) {
        logError("ERROR: Query failed for system_config - " . $conn->error);
        http_response_code(500);
        echo json_encode(['error' => $conn->error]);
        return;
    }

    $configArray = [];
    while ($row = $result->fetch_assoc()) {
        $configArray[$row['config_key']] = $row['config_value'];
    }

    logError("SUCCESS: Configuration retrieved (" . count($configArray) . " settings)");

    http_response_code(200);
    echo json_encode(['config' => $configArray]);
}


// Update system status (ON/OFF)
function updateSystemStatus() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $device_id = $data['device_id'] ?? '';
    $status = isset($data['status']) ? (int)$data['status'] : 0;
    
    // Use INSERT ... ON DUPLICATE KEY UPDATE
    $sql = "INSERT INTO system_status (device_id, status, last_seen) 
            VALUES (?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                last_seen = NOW()";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $device_id, $status);
    
    if ($stmt->execute()) {
        logError("SUCCESS: System status updated - Device: $device_id, Status: $status");
        http_response_code(200);
        echo json_encode(['success' => true]);
    } else {
        logError("ERROR: System status update failed - " . $stmt->error);
        http_response_code(500);
        echo json_encode(['error' => $stmt->error]);
    }
    
    $stmt->close();
}

// Log keypad event
function logKeypadEvent() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $device_id = $data['device_id'] ?? '';
    $key = $data['key'] ?? '';
    $action = $data['action'] ?? '';
    $success = isset($data['success']) ? ($data['success'] ? 1 : 0) : null;
    
    $sql = "INSERT INTO keypad_events (device_id, key_pressed, action, success) 
            VALUES (?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $device_id, $key, $action, $success);
    
    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => $stmt->error]);
    }
    
    $stmt->close();
}
?>
