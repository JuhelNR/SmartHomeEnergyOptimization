#include <WiFi.h>
#include <HTTPClient.h>
#include <SPIFFS.h>
#include <ArduinoJson.h>
#include <DHT.h>

const char* ssid = "WIFI_NAME";             // TODO: CHANGE THIS TO WIFI NAME
const char* password = "WIFI_PASS";         // TODO: CHANGE THIS TO WIFI PASSWORD
const char* serverUrl = "http://localhost/smarthomeenergyoptimization/serverAPI/api.php";

// PIN DEFINITIONS - ADJUST BASED ON YOUR WIRING
#define LDR_PIN 34  // ANALOG PIN FOR LDR

// KITCHEN PINS
#define KITCHEN_LIGHT_PIN 25
#define KITCHEN_FAN_PIN 26
#define KITCHEN_PIR_PIN 27
#define KITCHEN_DHT_PIN 14
#define KITCHEN_DHT_TYPE DHT11

// LIVING ROOM PINS
#define LIVING_LIGHT_PIN 32
#define LIVING_FAN_PIN 33
#define LIVING_PIR_PIN 13
#define LIVING_DHT_PIN 12
#define LIVING_DHT_TYPE DHT11

// BEDROOM PINS
#define BEDROOM_LIGHT_PIN 18
#define BEDROOM_FAN_PIN 19
#define BEDROOM_PIR_PIN 21
#define BEDROOM_DHT_PIN 22
#define BEDROOM_DHT_TYPE DHT11

// PWM SETTINGS FOR LIGHT
#define PWM_FREQ 5000
#define PWM_RESOLUTION 8
#define KITCHEN_PWM_CHANNEL 0
#define LIVING_PWM_CHANNEL 1
#define BEDROOM_PWM_CHANNEL 2

// TIMING
unsigned long lastSensorUpdate = 0;
unsigned long lastCommandCheck = 0;
const unsigned long SENSOR_UPDATE_INTERVAL = 5000;  // 5 SECONDS
const unsigned long COMMAND_CHECK_INTERVAL = 2000;  // 2 SECONDS

// DHT SENSORS
DHT kitchenDHT(KITCHEN_DHT_PIN, KITCHEN_DHT_TYPE);
DHT livingDHT(LIVING_DHT_PIN, LIVING_DHT_TYPE);
DHT bedroomDHT(BEDROOM_DHT_PIN, BEDROOM_DHT_TYPE);

// ROOM STRUCTURE
struct Room {
    String name;
    int lightPin;
    int fanPin;
    int pirPin;
    int pwmChannel;
    DHT* dhtSensor;

    bool lightStatus;
    int brightness;
    bool fanStatus;
    bool motionDetected;
    float temperature;
    unsigned long lastMotionTime;
    String mode;      // "AUTO" OR "MANUAL"
    bool sensorError;
};

Room rooms[3] = {
    {"kitchen", KITCHEN_LIGHT_PIN, KITCHEN_FAN_PIN, KITCHEN_PIR_PIN, KITCHEN_PWM_CHANNEL, &kitchenDHT, false, 0, false, false, 0, 0, "auto", false},
    {"living_room", LIVING_LIGHT_PIN, LIVING_FAN_PIN, LIVING_PIR_PIN, LIVING_PWM_CHANNEL, &livingDHT, false, 0, false, false, 0, 0, "auto", false},
    {"bedroom", BEDROOM_LIGHT_PIN, BEDROOM_FAN_PIN, BEDROOM_PIR_PIN, BEDROOM_PWM_CHANNEL, &bedroomDHT, false, 0, false, false, 0, 0, "auto", false}
};

// CONFIGURATION
float tempThreshold = 28.0;
unsigned long motionTimeout = 30000;  // 30 SECONDS
int ldrThresholdLow = 300;
int ldrThresholdHigh = 800;
bool systemError = false;

// FUNCTION TO LOG MESSAGES INTO FILE
void logToFile(String message) {
    File logFile = SPIFFS.open("/esp_post.log", FILE_APPEND);
    if (!logFile) {
        Serial.println("ERROR: COULD NOT OPEN LOG FILE");
        return;
    }
    logFile.println(message);
    logFile.close();
}

// RETURN GENERAL SYSTEM STATUS
bool getStatus() {
    if (systemError) return false;

    // CHECK WIFI CONNECTION
    if (WiFi.status() != WL_CONNECTED) {
        systemError = true;
        return false;
    }

    // CHECK SENSOR ERRORS
    for (int i = 0; i < 3; i++) {
        if (rooms[i].sensorError) {
            return false;
        }
    }
    return true;
}

void setup() {
    Serial.begin(115200);

    // MOUNT SPIFFS FILE SYSTEM
    if (!SPIFFS.begin(true)) {
        Serial.println("ERROR: SPIFFS MOUNT FAILED");
        logToFile("ERROR: SPIFFS MOUNT FAILED");
        return;
    }

    logToFile("=== DEVICE BOOTED ===");

    WiFi.begin(ssid, password);

    int attempts = 0;

    // TRY CONNECTING 5 TIMES
    while (WiFi.status() != WL_CONNECTED && attempts < 5) {
        delay(500);
        logToFile("TRYING WIFI CONNECTION... ATTEMPT " + String(attempts + 1));
        attempts++;
    }

    if (WiFi.status() == WL_CONNECTED) {
        logToFile("WIFI CONNECTED: " + WiFi.localIP().toString());
    } else {
        logToFile("ERROR: WIFI CONNECTION FAILED");
        systemError = true;
        return;
    }

    // INITIALIZE DHT SENSORS
    kitchenDHT.begin();
    livingDHT.begin();
    bedroomDHT.begin();

    // INITIALIZE GPIO
    pinMode(LDR_PIN, INPUT);

    for (int i = 0; i < 3; i++) {
        pinMode(rooms[i].lightPin, OUTPUT);
        pinMode(rooms[i].fanPin, OUTPUT);
        pinMode(rooms[i].pirPin, INPUT);

        // SETUP PWM
        ledcSetup(rooms[i].pwmChannel, PWM_FREQ, PWM_RESOLUTION);
        ledcAttachPin(rooms[i].lightPin, rooms[i].pwmChannel);

        digitalWrite(rooms[i].fanPin, LOW);
        ledcWrite(rooms[i].pwmChannel, 0);
    }

    // FETCH CONFIGURATION FROM SERVER
    fetchConfiguration();

    logToFile("=== SETUP COMPLETE ===");
}

void loop() {
    if (WiFi.status() != WL_CONNECTED) {
        logToFile("ERROR: WIFI DISCONNECTED");
        delay(5000);
        return;
    }

    unsigned long now = millis();

    // SENSOR UPDATE LOOP
    if (now - lastSensorUpdate >= SENSOR_UPDATE_INTERVAL) {
        lastSensorUpdate = now;

        int ldrValue = analogRead(LDR_PIN);

        for (int i = 0; i < 3; i++) {
            updateRoom(&rooms[i], ldrValue);
        }

        sendSystemStatus(getStatus());
    }

    // COMMAND CHECK LOOP
    if (now - lastCommandCheck >= COMMAND_CHECK_INTERVAL) {
        lastCommandCheck = now;
        checkCommands();
    }
}

void updateRoom(Room* room, int ldrValue) {
    room->temperature = room->dhtSensor->readTemperature();
    room->motionDetected = digitalRead(room->pirPin);

    if (isnan(room->temperature)) {
        room->sensorError = true;
        sendAlert("sensor_error", room->name, "DHT READ FAILED", "warning");
        return;
    } else {
        room->sensorError = false;
    }

    sendSensorData(room->name, room->temperature, room->motionDetected, ldrValue);

    // AUTO MODE LOGIC
    if (room->mode == "auto") {
        if (room->motionDetected) {
            room->lastMotionTime = millis();
            room->lightStatus = true;
        } else if (millis() - room->lastMotionTime > motionTimeout) {
            room->lightStatus = false;
        }

        if (room->lightStatus) {
            room->brightness = constrain(map(ldrValue, ldrThresholdLow, ldrThresholdHigh, 255, 50), 50, 255);
        } else {
            room->brightness = 0;
        }

        room->fanStatus = room->temperature > tempThreshold;
    }

    // APPLY OUTPUTS
    ledcWrite(room->pwmChannel, room->brightness);
    digitalWrite(room->fanPin, room->fanStatus ? HIGH : LOW);

    sendDeviceStatus(room->name, "light", room->lightStatus, room->brightness, room->mode);
    sendDeviceStatus(room->name, "fan", room->fanStatus, 0, room->mode);
}

void sendSensorData(String room, float temp, bool motion, int light) {
    HTTPClient http;
    http.begin(String(serverUrl) + "?action=update_sensors");
    http.addHeader("Content-Type", "application/json");

    StaticJsonDocument<200> doc;
    doc["room"] = room;
    doc["temperature"] = temp;
    doc["motion_detected"] = motion;
    doc["light_level"] = light;

    String payload;
    serializeJson(doc, payload);
    http.POST(payload);
    http.end();
}

void sendDeviceStatus(String room, String deviceType, bool status, int brightness, String mode) {
    HTTPClient http;
    http.begin(String(serverUrl) + "?action=update_device_status");
    http.addHeader("Content-Type", "application/json");

    StaticJsonDocument<200> doc;
    doc["room"] = room;
    doc["device_type"] = deviceType;
    doc["status"] = status;
    doc["brightness"] = brightness;
    doc["mode"] = mode;

    String payload;
    serializeJson(doc, payload);
    http.POST(payload);
    http.end();
}

void sendSystemStatus(bool stat) {
    HTTPClient http;
    http.begin(String(serverUrl) + "?action=update_system_status");
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    String data = "device_ID=" + WiFi.macAddress() + "&status=" + String(stat ? 1 : 0);
    http.POST(data);
    http.end();
}

void checkCommands() {
    HTTPClient http;
    http.begin(String(serverUrl) + "?action=get_commands");

    if (http.GET() == 200) {
        StaticJsonDocument<1024> doc;
        deserializeJson(doc, http.getString());

        for (JsonObject cmd : doc["commands"].as<JsonArray>()) {
            for (int i = 0; i < 3; i++) {
                if (rooms[i].name == cmd["room"].as<String>()) {
                    rooms[i].mode = cmd["mode"].as<String>();
                    rooms[i].lightStatus = cmd["device_type"] == "light" && cmd["action"] == "on";
                    rooms[i].fanStatus = cmd["device_type"] == "fan" && cmd["action"] == "on";
                }
            }
        }
    }
    http.end();
}

void sendAlert(String type, String room, String message, String severity) {
    HTTPClient http;
    http.begin(String(serverUrl) + "?action=add_alert");
    http.addHeader("Content-Type", "application/json");

    StaticJsonDocument<200> doc;
    doc["alert_type"] = type;
    doc["room"] = room;
    doc["message"] = message;
    doc["severity"] = severity;

    String payload;
    serializeJson(doc, payload);
    http.POST(payload);
    http.end();
}

void fetchConfiguration() {
    HTTPClient http;
    http.begin(String(serverUrl) + "?action=get_config");

    if (http.GET() == 200) {
        StaticJsonDocument<512> doc;
        deserializeJson(doc, http.getString());

        JsonObject cfg = doc["config"];
        tempThreshold = cfg["temp_threshold"];
        motionTimeout = cfg["motion_timeout"].as<int>() * 1000;
        ldrThresholdLow = cfg["ldr_threshold_low"];
        ldrThresholdHigh = cfg["ldr_threshold_high"];
    }
    http.end();
}
