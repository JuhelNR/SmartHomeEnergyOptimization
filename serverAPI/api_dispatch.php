<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once "../config/connect.php";

//ERROR LOG FILE PATH CONFIG
$logDir = "../monitoring";
$logFile = $logDir . "/api_dispatcher.log";

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

$action = $_GET['action'] ?? '';

try {
    switch($action) {
        // Your existing cases...
        case 'get_commands':
            getControlCommands();
            break;

        case 'get_keypad_events':
            getKeypadEvents();
            break;

        case 'get_system_status':
            getSystemStatus();
            break;

        case 'send_command':
                sendControlCommand();
            break;
        
        // NEW: Dashboard endpoints
        case 'get_latest_data':
            getLatestData();
            break;
        
        case 'get_room_data':
            getRoomData();
            break;
        
        case 'get_alerts':
            getAlerts();
            break;
        
        case 'get_chart_data':
            getChartData();
            break;

        case 'get_config':
            system_config();
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ==================== EXISTING FUNCTIONS ====================
// (Keep your existing getControlCommands function here)

function getControlCommands() {
    global $conn;

    $sql = "SELECT * FROM control_commands WHERE processed = FALSE ORDER BY created_at ASC";
    $result = $conn->query($sql);

    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => $conn->error]);
        return;
    }

    $commands = [];
    while ($row = $result->fetch_assoc()) {
        $commands[] = $row;
    }

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
        }
    }

    http_response_code(200);
    echo json_encode(['commands' => $commands]);
}

// ==================== NEW DASHBOARD FUNCTIONS ====================

// Get latest sensor data for all rooms
function getLatestData() {
    global $conn;
    
    $sql = "SELECT 
                rs.room,
                rs.temperature,
                rs.humidity,
                rs.motion_detected,
                rs.light_level,
                rs.current_reading,
                rs.timestamp
            FROM room_sensors rs
            INNER JOIN (
                SELECT room, MAX(id) as max_id
                FROM room_sensors
                GROUP BY room
            ) latest ON rs.room = latest.room AND rs.id = latest.max_id
            ORDER BY rs.room";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception($conn->error);
    }
    
    $rooms = [];
    while ($row = $result->fetch_assoc()) {
        // Get device status for this room
        $deviceSql = "SELECT device_type, status, brightness 
                      FROM device_status 
                      WHERE room = ? 
                      ORDER BY last_updated DESC";
        $stmt = $conn->prepare($deviceSql);
        $stmt->bind_param("s", $row['room']);
        $stmt->execute();
        $deviceResult = $stmt->get_result();
        
        $lightStatus = false;
        $brightness = 0;
        $fanStatus = false;
        
        while ($device = $deviceResult->fetch_assoc()) {
            if ($device['device_type'] === 'light') {
                $lightStatus = (bool)$device['status'];
                $brightness = (int)$device['brightness'];
            } elseif ($device['device_type'] === 'fan') {
                $fanStatus = (bool)$device['status'];
            }
        }
        $stmt->close();
        
        $rooms[] = [
            'room' => $row['room'],
            'temperature' => (float)$row['temperature'],
            'humidity' => (float)$row['humidity'],
            'motion_detected' => (bool)$row['motion_detected'],
            'light_level' => (int)$row['light_level'],
            'current' => (float)$row['current_reading'],
            'light_status' => $lightStatus,
            'brightness' => $brightness,
            'fan_status' => $fanStatus,
            'timestamp' => $row['timestamp']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $rooms]);
}

// Get specific room data
function getRoomData() {
    global $conn;
    
    $room = $_GET['room'] ?? '';
    
    if (empty($room)) {
        throw new Exception('Room parameter required');
    }
    
    $sql = "SELECT * FROM room_sensors 
            WHERE room = ? 
            ORDER BY timestamp DESC 
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $room);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No data found']);
    }
    
    $stmt->close();
}

// Send control command
function sendControlCommand() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $room = $data['room'] ?? '';
    $device_type = $data['device_type'] ?? '';
    $action = $data['action'] ?? '';
    $value = isset($data['value']) ? (int)$data['value'] : 255;
    $mode = $data['mode'] ?? 'manual';
    
    // Validate inputs
    if (empty($room) || empty($device_type) || empty($action)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: room, device_type, action']);
        return;
    }
    
    // Insert command into database
    $sql = "INSERT INTO control_commands (room, device_type, action, value, mode, processed, created_at) 
            VALUES (?, ?, ?, ?, ?, FALSE, NOW())";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Database prepare failed: ' . $conn->error]);
        return;
    }
    
    $stmt->bind_param("sssis", $room, $device_type, $action, $value, $mode);
    
    if ($stmt->execute()) {
        $command_id = $stmt->insert_id;
        
        http_response_code(200);
        echo json_encode([
            'success' => true, 
            'message' => 'Command sent successfully',
            'command_id' => $command_id,
            'room' => $room,
            'device' => $device_type,
            'action' => $action,
            'mode' => $mode
        ]);
        
        // Log success
        error_log("Control command sent: Room=$room, Device=$device_type, Action=$action, Mode=$mode");
        
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to insert command: ' . $stmt->error]);
        error_log("Control command failed: " . $stmt->error);
    }
    
    $stmt->close();
}


//Get system configuration

function system_config(){
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

// Get recent alerts
function getAlerts() {
    global $conn;
    
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    $sql = "SELECT 
                alert_type,
                room,
                message,
                severity,
                created_at
            FROM system_alerts 
            ORDER BY created_at DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $alerts = [];
    while ($row = $result->fetch_assoc()) {
        $alerts[] = [
            'alert_type' => $row['alert_type'],
            'room' => $row['room'],
            'message' => $row['message'],
            'severity' => $row['severity'],
            'created_at' => $row['created_at']
        ];
    }
    
    echo json_encode(['success' => true, 'alerts' => $alerts]);
    $stmt->close();
}

// Get historical data for charts
function getChartData() {
    global $conn;
    
    $room = $_GET['room'] ?? 'living_room';
    $hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
    
    $sql = "SELECT 
                DATE_FORMAT(timestamp, '%H:%i') as time,
                temperature,
                humidity,
                light_level,
                timestamp
            FROM room_sensors 
            WHERE room = ? 
            AND timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY timestamp ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $room, $hours);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'time' => $row['time'],
            'temperature' => (float)$row['temperature'],
            'humidity' => (float)$row['humidity'],
            'light_level' => (int)$row['light_level']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
    $stmt->close();
}

// Get recent keypad events
function getKeypadEvents() {
    global $conn;
    
    $since = $_GET['since'] ?? '10 SECOND';
    
    $sql = "SELECT id, key_pressed, action, success, timestamp 
            FROM keypad_events 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $since)
            ORDER BY timestamp ASC";
    
    $result = $conn->query($sql);
    
    $events = [];
    while ($row = $result->fetch_assoc()) {
        $events[] = [
            'id' => (int)$row['id'],
            'key' => $row['key_pressed'],
            'action' => $row['action'],
            'success' => (bool)$row['success'],
            'timestamp' => $row['timestamp']
        ];
    }
    
    echo json_encode(['success' => true, 'events' => $events]);
}

// Get system status
function getSystemStatus() {
    global $conn;
    
    $sql = "SELECT status, last_seen 
            FROM system_status 
            WHERE device_id = 'ESP32_MAIN_001'
            ORDER BY last_seen DESC 
            LIMIT 1";
    
    $result = $conn->query($sql);
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'system_on' => (bool)$row['status'],
            'last_seen' => $row['last_seen']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'system_on' => false,
            'last_seen' => null
        ]);
    }
}

?>
