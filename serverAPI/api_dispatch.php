<?php
require "../vendor/autoload.php";

//ERROR LOG FILE PATH CONFIG
$logDir = "../monitoring";
$logFile = $logDir . "/api_dispatch.log";

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

// Set headers for JSON responses
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

logError("DISPATCH REQUEST: Method=$method, Action=$action");

// Route dashboard requests
try {
    switch($action) {
        case 'get_dashboard_data':
            if ($method === 'GET' || $method === 'POST') {
                getDashboardData();
            } else {
                logError("Invalid method for get_dashboard_data: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'get_latest_reading':
            if ($method === 'GET' || $method === 'POST') {
                getLatestReading();
            } else {
                logError("Invalid method for get_latest_reading: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'send_command':
            if ($method === 'POST') {
                sendCommand();
            } else {
                logError("Invalid method for send_command: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'get_alerts':
            if ($method === 'GET') {
                getAlerts();
            } else {
                logError("Invalid method for get_alerts: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'resolve_alert':
            if ($method === 'POST') {
                resolveAlert();
            } else {
                logError("Invalid method for resolve_alert: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'get_room_data':
            if ($method === 'GET') {
                getRoomData();
            } else {
                logError("Invalid method for get_room_data: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'update_config':
            if ($method === 'POST') {
                updateConfig();
            } else {
                logError("Invalid method for update_config: $method");
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        default:
            // Default to your original behavior - get latest reading
            getLatestReading();
    }
} catch (Exception $e) {
    logError("EXCEPTION: " . $e->getMessage());
    echo json_encode(['error' => 'Server error occurred: ' . $e->getMessage()]);
}

// ==================== FUNCTION DEFINITIONS ====================

function getLatestReading() {
    try {
        //QUERY DATABASE
        $result = conn::executeQuery("SELECT * FROM readings ORDER BY id DESC LIMIT 1");
        $row = $result->fetch(PDO::FETCH_ASSOC);

        //IF DATA FETCH FAILS
        if (!$row) {
            logError("NO RECORDS FOUND IN readings TABLE.");
            echo json_encode(["error" => "No data available"]);
            exit();
        } else {
            //RETURN RESULT AS JSON FILE
            echo json_encode($row);
            logError("SUCCESS: Latest reading dispatched");

            //CLOSE CONNECTION
            conn::closeConnection();
        }
    } catch(Exception $e) {
        //LOG EXCEPTION ERROR
        logError("EXCEPTION: " . $e->getMessage());
        echo json_encode(["error" => "Server error occurred"]);
        exit();
    }
}

// Get complete dashboard data (all rooms, devices, alerts)
function getDashboardData() {
    try {
        // Get latest sensor readings for each room
        $sensorQuery = "SELECT sr1.* FROM room_sensors sr1
                       INNER JOIN (
                           SELECT room, MAX(timestamp) as max_time
                           FROM room_sensors
                           GROUP BY room
                       ) sr2 ON sr1.room = sr2.room AND sr1.timestamp = sr2.max_time";
        $sensorResult = conn::executeQuery($sensorQuery);

        $sensors = [];
        while ($row = $sensorResult->fetch(PDO::FETCH_ASSOC)) {
            $sensors[] = $row;
        }

        // Get device status
        $deviceQuery = "SELECT * FROM device_status ORDER BY room, device_type";
        $deviceResult = conn::executeQuery($deviceQuery);

        $devices = [];
        while ($row = $deviceResult->fetch(PDO::FETCH_ASSOC)) {
            $devices[] = $row;
        }

        // Get unresolved alerts
        $alertQuery = "SELECT * FROM system_alerts 
                      WHERE resolved = FALSE 
                      ORDER BY created_at DESC 
                      LIMIT 10";
        $alertResult = conn::executeQuery($alertQuery);

        $alerts = [];
        while ($row = $alertResult->fetch(PDO::FETCH_ASSOC)) {
            $alerts[] = $row;
        }

        // Get system status
        $statusQuery = "SELECT * FROM system_status ORDER BY last_seen DESC LIMIT 1";
        $statusResult = conn::executeQuery($statusQuery);
        $systemStatus = $statusResult->fetch(PDO::FETCH_ASSOC);

        logError("SUCCESS: Dashboard data retrieved - " . count($sensors) . " rooms, " . count($devices) . " devices");

        echo json_encode([
            'sensors' => $sensors,
            'devices' => $devices,
            'alerts' => $alerts,
            'system_status' => $systemStatus,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to get dashboard data - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Dashboard sends control command to ESP32
function sendCommand() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        logError("ERROR: Invalid JSON data for send_command");
        echo json_encode(['error' => 'Invalid JSON data']);
        return;
    }

    logError("Command received from dashboard: " . json_encode($data));

    try {
        $device_id = $data['device_id'] ?? '';
        $room = $data['room'] ?? '';
        $device_type = $data['device_type'] ?? '';
        $action = $data['action'] ?? '';
        $value = $data['value'] ?? null;
        $mode = $data['mode'] ?? 'manual';

        $query = "INSERT INTO control_commands (device_id, room, device_type, action, value, mode) 
                  VALUES (?, ?, ?, ?, ?, ?)";

        conn::executeQuery($query, [$device_id, $room, $device_type, $action, $value, $mode]);

        logError("SUCCESS: Command queued - " . $room . " " . $device_type . " " . $action);
        echo json_encode(['success' => true, 'message' => 'Command queued for ESP32']);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to queue command - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Get system alerts
function getAlerts() {
    $resolved = $_GET['resolved'] ?? 'false';
    $limit = $_GET['limit'] ?? 50;

    try {
        $query = "SELECT * FROM system_alerts 
                 WHERE resolved = ? 
                 ORDER BY created_at DESC 
                 LIMIT ?";

        $result = conn::executeQuery($query, [$resolved === 'true' ? 1 : 0, (int)$limit]);

        $alerts = [];
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $alerts[] = $row;
        }

        logError("SUCCESS: Retrieved " . count($alerts) . " alerts");
        echo json_encode(['alerts' => $alerts]);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to get alerts - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Resolve an alert
function resolveAlert() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['alert_id'])) {
        logError("ERROR: Invalid data for resolve_alert");
        echo json_encode(['error' => 'Invalid data']);
        return;
    }

    try {
        $alert_id = $data['alert_id'];

        $query = "UPDATE system_alerts 
                 SET resolved = TRUE, resolved_at = NOW() 
                 WHERE id = ?";

        conn::executeQuery($query, [$alert_id]);

        logError("SUCCESS: Alert #" . $alert_id . " resolved");
        echo json_encode(['success' => true, 'message' => 'Alert resolved']);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to resolve alert - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Get data for a specific room
function getRoomData() {
    $room = $_GET['room'] ?? '';

    if (!$room) {
        logError("ERROR: Room parameter missing");
        echo json_encode(['error' => 'Room parameter required']);
        return;
    }

    try {
        // Get latest sensor reading for this room
        $sensorQuery = "SELECT * FROM room_sensors 
                       WHERE room = ? 
                       ORDER BY timestamp DESC 
                       LIMIT 1";
        $sensorResult = conn::executeQuery($sensorQuery, [$room]);
        $sensorData = $sensorResult->fetch(PDO::FETCH_ASSOC);

        // Get device status for this room
        $deviceQuery = "SELECT * FROM device_status WHERE room = ?";
        $deviceResult = conn::executeQuery($deviceQuery, [$room]);

        $devices = [];
        while ($row = $deviceResult->fetch(PDO::FETCH_ASSOC)) {
            $devices[] = $row;
        }

        // Get recent sensor history (last 10 readings)
        $historyQuery = "SELECT * FROM room_sensors 
                        WHERE room = ? 
                        ORDER BY timestamp DESC 
                        LIMIT 10";
        $historyResult = conn::executeQuery($historyQuery, [$room]);

        $history = [];
        while ($row = $historyResult->fetch(PDO::FETCH_ASSOC)) {
            $history[] = $row;
        }

        logError("SUCCESS: Room data retrieved for " . $room);

        echo json_encode([
            'room' => $room,
            'current_sensors' => $sensorData,
            'devices' => $devices,
            'history' => $history
        ]);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to get room data - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Update system configuration
function updateConfig() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['config_key']) || !isset($data['config_value'])) {
        logError("ERROR: Invalid data for update_config");
        echo json_encode(['error' => 'Invalid data']);
        return;
    }

    try {
        $config_key = $data['config_key'];
        $config_value = $data['config_value'];

        $query = "UPDATE system_config 
                 SET config_value = ?, last_updated = NOW() 
                 WHERE config_key = ?";

        conn::executeQuery($query, [$config_value, $config_key]);

        logError("SUCCESS: Configuration updated - " . $config_key . " = " . $config_value);
        echo json_encode(['success' => true, 'message' => 'Configuration updated']);

        conn::closeConnection();
    } catch(Exception $e) {
        logError("ERROR: Failed to update config - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>