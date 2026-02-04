/**
 * ============================================
 * SMART HOME ENERGY OPTIMIZATION SYSTEM
 * ESP32 COMPLETE CODE - Properly Ordered
 * ============================================
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <DHT.h>
#include <Keypad.h>
#include <ESP32Servo.h>

// ============================================
// WIFI & SERVER CONFIGURATION
// ============================================
const char* WIFI_SSID = "BravoTwo";
const char* WIFI_PASSWORD = "Alphaone12$";
const char* SERVER_INGEST_URL = "http://192.168.8.75/smarthome/api/api_ingest.php";
const char* SERVER_DISPATCH_URL = "http://192.168.8.75/smarthome/api/api_dispatcher.php";
const char* DEVICE_ID = "ESP32_MAIN_001";

// ============================================
// PIN DEFINITIONS
// ============================================
#define SERVO_PIN 13
#define LDR_PIN 36

// Kitchen
#define PIR_KITCHEN 27
#define DHT_KITCHEN 14
#define MOSFET_KITCHEN 25
#define CURRENT_KITCHEN 35

// Living Room
#define PIR_LIVING 26
#define DHT_LIVING 15
#define MOSFET_BULB_LIVING 16
#define MOSFET_FAN_LIVING 17
#define CURRENT_LIVING 34

// Bedroom
#define PIR_BEDROOM 33
#define MOSFET_BULB_BEDROOM 0
#define MOSFET_FAN_BEDROOM 32
#define CURRENT_BEDROOM 39

#define DHT_TYPE DHT11

// ============================================
// THRESHOLDS & TIMING
// ============================================
float DARKNESS_THRESHOLD = 2500;
float HOT_THRESHOLD = 27.0;
int MOTION_THRESHOLD = 5;
unsigned long TIMER_DURATION = 30000;
#define ZERO_POINT 2.318

const unsigned long SENSOR_UPDATE_INTERVAL = 10000;
const unsigned long COMMAND_CHECK_INTERVAL = 5000;
const unsigned long STATUS_UPDATE_INTERVAL = 30000;
const unsigned long WIFI_CHECK_INTERVAL = 60000;
const unsigned long CONFIG_RELOAD_INTERVAL = 300000;

// ============================================
// PWM SETTINGS
// ============================================
const int pwmFreq = 5000;
const int pwmResolution = 8;

// ============================================
// KEYPAD CONFIGURATION
// ============================================
const byte ROWS = 4;
const byte COLS = 4;
char keys[ROWS][COLS] = {
  {'1','2','3','A'},
  {'4','5','6','B'},
  {'7','8','9','C'},
  {'*','0','#','D'}
};
byte rowPins[ROWS] = {23, 22, 21, 5};
byte colPins[COLS] = {18, 19, 4, 2};

Keypad keypad = Keypad(makeKeymap(keys), rowPins, colPins, ROWS, COLS);
Servo doorServo;

// ============================================
// DHT SENSORS
// ============================================
DHT dhtKitchen(DHT_KITCHEN, DHT_TYPE);
DHT dhtLiving(DHT_LIVING, DHT_TYPE);

// ============================================
// ROOM STRUCTURE
// ============================================
struct Room {
    String name;
    int pirPin;
    int mosfetPin;
    int fanPin;
    int currentPin;
    DHT* dhtSensor;
    
    bool bulbActive;
    int brightness;
    bool fanActive;
    bool motionDetected;
    float temperature;
    float humidity;
    float current;
    int motionCounter;
    unsigned long bulbOffTime;
    unsigned long fanOffTime;
    unsigned long lastMotionTime;
    
    String mode;
    bool manualLightOn;
    bool manualFanOn;
    int manualBrightness;
    
    bool sensorError;
    int errorCount;
};

Room rooms[3] = {
    {"kitchen", PIR_KITCHEN, MOSFET_KITCHEN, -1, CURRENT_KITCHEN, &dhtKitchen, 
     false, 0, false, false, 0, 0, 0, 0, 0, 0, 0, "auto", false, false, 0, false, 0},
    
    {"living_room", PIR_LIVING, MOSFET_BULB_LIVING, MOSFET_FAN_LIVING, CURRENT_LIVING, &dhtLiving, 
     false, 0, false, false, 0, 0, 0, 0, 0, 0, 0, "auto", false, false, 0, false, 0},
    
    {"bedroom", PIR_BEDROOM, MOSFET_BULB_BEDROOM, MOSFET_FAN_BEDROOM, CURRENT_BEDROOM, NULL, 
     false, 0, false, false, 0, 0, 0, 0, 0, 0, 0, "auto", false, false, 0, false, 0}
};

// ============================================
// SYSTEM VARIABLES
// ============================================
String password = "1234";
String input = "";
bool systemON = false;
bool wifiConnected = false;
int wifiReconnectAttempts = 0;
bool configLoaded = false;

unsigned long lastSensorUpdate = 0;
unsigned long lastCommandCheck = 0;
unsigned long lastStatusUpdate = 0;
unsigned long lastWifiCheck = 0;
unsigned long lastConfigReload = 0;

int successfulUploads = 0;
int failedUploads = 0;
int currentUploadRoom = 0;

// ============================================
// FUNCTION DECLARATIONS (IMPORTANT - MUST BE BEFORE FUNCTIONS)
// ============================================
void setupWiFi();
void reconnectWiFi();
void checkKeypad();
bool checkMotion(Room* room);
float readCurrent(int pin);
int calculateBrightness(int ldrValue);
void turnOffAll();
void controlRoom(Room* room, int lightLevel);
void sendSensorDataBatched();
void sendDeviceStatusQuick(Room* room);
void checkCommands();
void sendSystemStatus();
void loadConfigFromServer();
void printSystemStatus();
void sendNotification(String alertType, String message, String severity, String room);
void sendKeypadEvent(String key, String action, bool success);

// ============================================
// SETUP FUNCTION
// ============================================
void setup() {
    Serial.begin(115200);
    delay(1000);
    
    Serial.println("\n\n==========================================");
    Serial.println("   SMART HOME ENERGY OPTIMIZATION SYSTEM");
    Serial.println("   Device ID: " + String(DEVICE_ID));
    Serial.println("==========================================");
    
    doorServo.attach(SERVO_PIN);
    doorServo.write(0);
    
    dhtKitchen.begin();
    dhtLiving.begin();
    
    pinMode(LDR_PIN, INPUT);
    
    for (int i = 0; i < 3; i++) {
        pinMode(rooms[i].pirPin, INPUT);
        ledcAttach(rooms[i].mosfetPin, pwmFreq, pwmResolution);
        ledcWrite(rooms[i].mosfetPin, 0);
        
        if (rooms[i].fanPin != -1) {
            pinMode(rooms[i].fanPin, OUTPUT);
            digitalWrite(rooms[i].fanPin, LOW);
        }
    }
    
    Serial.println("Waiting 30 seconds for PIR warmup...");
    delay(30000);
    
    setupWiFi();
    
    if (wifiConnected) {
        sendNotification(
            "WIFI_CONNECTED",
            "WiFi connected. IP: " + WiFi.localIP().toString() + ", Signal: " + String(WiFi.RSSI()) + "dBm",
            "info",
            "system"
        );
        
        Serial.println("\n📡 Loading configuration from server...");
        loadConfigFromServer();
        
        if (configLoaded) {
            sendNotification(
                "CONFIG_LOADED",
                "Config loaded. Temp: " + String(HOT_THRESHOLD) + "°C, Timeout: " + String(TIMER_DURATION / 1000) + "s",
                "info",
                "system"
            );
        }
        
        sendNotification(
            "SYSTEM_START",
            "Smart Home System started successfully",
            "info",
            "system"
        );
        
        sendSystemStatus();
    }
    
    Serial.println("\n==========================================");
    Serial.println("Ready! Enter password: 1234");
    Serial.println("==========================================\n");
}

// ============================================
// MAIN LOOP
// ============================================
void loop() {
    unsigned long now = millis();
    
    checkKeypad();
    
    if (now - lastWifiCheck >= WIFI_CHECK_INTERVAL) {
        lastWifiCheck = now;
        if (WiFi.status() != WL_CONNECTED) {
            wifiConnected = false;
            reconnectWiFi();
        }
    }
    
    if (!systemON) {
        delay(10);
        return;
    }
    
    int lightLevel = analogRead(LDR_PIN);
    for (int i = 0; i < 3; i++) {
        controlRoom(&rooms[i], lightLevel);
    }
    
    if (wifiConnected && (now - lastSensorUpdate >= SENSOR_UPDATE_INTERVAL)) {
        lastSensorUpdate = now;
        sendSensorDataBatched();
    }
    
    if (wifiConnected && (now - lastCommandCheck >= COMMAND_CHECK_INTERVAL)) {
        lastCommandCheck = now;
        checkCommands();
    }
    
    if (wifiConnected && (now - lastStatusUpdate >= STATUS_UPDATE_INTERVAL)) {
        lastStatusUpdate = now;
        sendSystemStatus();
    }
    
    if (wifiConnected && (now - lastConfigReload >= CONFIG_RELOAD_INTERVAL)) {
        lastConfigReload = now;
        Serial.println("\n🔄 Reloading configuration...");
        loadConfigFromServer();
    }
    
    static unsigned long lastPrint = 0;
    if (now - lastPrint >= 10000) {
        lastPrint = now;
        printSystemStatus();
    }
    
    delay(10);
}

// ============================================
// SEND NOTIFICATION TO SERVER
// ============================================
void sendNotification(String alertType, String message, String severity = "info", String room = "system") {
    if (!wifiConnected) return;
    
    HTTPClient http;
    http.begin(String(SERVER_INGEST_URL) + "?action=add_alert");
    http.addHeader("Content-Type", "application/json");
    http.setTimeout(3000);
    
    StaticJsonDocument<256> doc;
    doc["device_id"] = DEVICE_ID;
    doc["alert_type"] = alertType;
    doc["room"] = room;
    doc["message"] = message;
    doc["severity"] = severity;
    
    String payload;
    serializeJson(doc, payload);
    http.POST(payload);
    http.end();
}

// ============================================
// SEND KEYPAD EVENT TO SERVER
// ============================================
void sendKeypadEvent(String key, String action, bool success = false) {
    if (!wifiConnected) return;
    
    HTTPClient http;
    http.begin(String(SERVER_INGEST_URL) + "?action=keypad_event");
    http.addHeader("Content-Type", "application/json");
    http.setTimeout(2000);
    
    StaticJsonDocument<128> doc;
    doc["device_id"] = DEVICE_ID;
    doc["key"] = key;
    doc["action"] = action;
    doc["success"] = success;
    
    String payload;
    serializeJson(doc, payload);
    http.POST(payload);
    http.end();
}

// ============================================
// LOAD CONFIG FROM SERVER
// ============================================
void loadConfigFromServer() {
    if (!wifiConnected) {
        Serial.println("❌ Cannot load config - no WiFi");
        return;
    }
    
    HTTPClient http;
    http.begin(String(SERVER_INGEST_URL) + "?action=get_config");
    http.setTimeout(10000);
    
    int httpCode = http.GET();
    
    if (httpCode == 200) {
        String response = http.getString();
        
        StaticJsonDocument<1024> doc;
        DeserializationError error = deserializeJson(doc, response);
        
        if (error) {
            Serial.println("❌ JSON Parse Error");
            http.end();
            return;
        }
        
        if (!doc.containsKey("config")) {
            Serial.println("❌ Missing config key");
            http.end();
            return;
        }
        
        JsonObject config = doc["config"];
        
        if (config.containsKey("temp_threshold")) {
            HOT_THRESHOLD = config["temp_threshold"].as<String>().toFloat();
            Serial.println("  ✓ Temp threshold: " + String(HOT_THRESHOLD) + "°C");
        }
        
        if (config.containsKey("ldr_threshold_high")) {
            DARKNESS_THRESHOLD = config["ldr_threshold_high"].as<String>().toFloat();
            Serial.println("  ✓ Darkness threshold: " + String(DARKNESS_THRESHOLD));
        }
        
        if (config.containsKey("motion_timeout")) {
            int timeoutSeconds = config["motion_timeout"].as<String>().toInt();
            TIMER_DURATION = timeoutSeconds * 1000;
            Serial.println("  ✓ Motion timeout: " + String(timeoutSeconds) + "s");
        }
        
        configLoaded = true;
        Serial.println("\n✅ Configuration loaded!\n");
        
    } else {
        Serial.println("❌ Config fetch failed - HTTP " + String(httpCode));
    }
    
    http.end();
}

// ============================================
// WIFI SETUP
// ============================================
void setupWiFi() {
    Serial.println("Connecting to WiFi: " + String(WIFI_SSID));
    
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
        delay(500);
        Serial.print(".");
        attempts++;
    }
    Serial.println();
    
    if (WiFi.status() == WL_CONNECTED) {
        wifiConnected = true;
        Serial.println("✅ WiFi Connected!");
        Serial.println("   IP: " + WiFi.localIP().toString());
        Serial.println("   Signal: " + String(WiFi.RSSI()) + " dBm");
    } else {
        wifiConnected = false;
        Serial.println("❌ WiFi Failed!");
    }
}

// ============================================
// WIFI RECONNECTION
// ============================================
void reconnectWiFi() {
    Serial.println("Reconnecting WiFi...");
    WiFi.disconnect();
    delay(1000);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 10) {
        delay(500);
        Serial.print(".");
        attempts++;
    }
    Serial.println();
    
    wifiConnected = (WiFi.status() == WL_CONNECTED);
    
    if (wifiConnected) {
        Serial.println("✅ WiFi Reconnected!");
        sendNotification(
            "WIFI_RECONNECTED",
            "WiFi restored. IP: " + WiFi.localIP().toString(),
            "info",
            "system"
        );
    }
}

// ============================================
// KEYPAD HANDLER
// ============================================
void checkKeypad() {
    char key = keypad.getKey();
    
    if (key) {
        Serial.println("Key: " + String(key));
        
        sendKeypadEvent(String(key), "key_press", false);
        
        if (key == '#') {
            if (input == password) {
                systemON = !systemON;
                
                if (systemON) {
                    Serial.println("\n>>> SYSTEM ON <<<\n");
                    doorServo.write(90);
                    
                    sendKeypadEvent("#", "unlock_success", true);
                    sendNotification(
                        "SYSTEM_ACTIVATED",
                        "System activated via keypad",
                        "info",
                        "system"
                    );
                    
                } else {
                    Serial.println("\n>>> SYSTEM OFF <<<\n");
                    turnOffAll();
                    doorServo.write(0);
                    
                    sendKeypadEvent("#", "lock_success", true);
                    sendNotification(
                        "SYSTEM_DEACTIVATED",
                        "System deactivated via keypad",
                        "info",
                        "system"
                    );
                }
            } else {
                Serial.println("WRONG PASSWORD!");
                
                sendKeypadEvent("#", "unlock_failed", false);
                sendNotification(
                    "WRONG_PASSWORD",
                    "Invalid password attempt",
                    "warning",
                    "system"
                );
            }
            input = "";
            
        } else if (key == '*') {
            input = "";
            Serial.println("Cleared");
            sendKeypadEvent("*", "clear", false);
            
        } else {
            input += key;
            Serial.print("Input: ");
            for (int i = 0; i < input.length(); i++) Serial.print("*");
            Serial.println();
        }
    }
}

// ============================================
// MOTION DETECTION
// ============================================
bool checkMotion(Room* room) {
    if (digitalRead(room->pirPin) == HIGH) {
        room->motionCounter++;
        if (room->motionCounter >= MOTION_THRESHOLD) {
            room->motionCounter = 0;
            room->motionDetected = true;
            room->lastMotionTime = millis();
            return true;
        }
    } else {
        if (room->motionCounter > 0) room->motionCounter--;
    }
    
    if (millis() - room->lastMotionTime > TIMER_DURATION) {
        room->motionDetected = false;
    }
    
    return false;
}

// ============================================
// CURRENT READING
// ============================================
float readCurrent(int pin) {
    int raw = analogRead(pin);
    float voltage = raw * (3.3 / 4095.0);
    return abs((voltage - ZERO_POINT) / 0.100);
}

// ============================================
// BRIGHTNESS CALCULATION
// ============================================
int calculateBrightness(int ldrValue) {
    if (ldrValue >= DARKNESS_THRESHOLD) return 0;
    int brightness = map(ldrValue, 0, DARKNESS_THRESHOLD, 255, 50);
    return constrain(brightness, 50, 255);
}

// ============================================
// TURN OFF ALL DEVICES
// ============================================
void turnOffAll() {
    for (int i = 0; i < 3; i++) {
        ledcWrite(rooms[i].mosfetPin, 0);
        if (rooms[i].fanPin != -1) {
            digitalWrite(rooms[i].fanPin, LOW);
        }
        rooms[i].bulbActive = false;
        rooms[i].fanActive = false;
        rooms[i].bulbOffTime = 0;
        rooms[i].fanOffTime = 0;
        rooms[i].brightness = 0;
    }
    Serial.println("All OFF");
}

// ============================================
// ROOM CONTROL LOGIC
// ============================================
void controlRoom(Room* room, int lightLevel) {
    unsigned long now = millis();
    bool isDark = (lightLevel < DARKNESS_THRESHOLD);
    
    // Read sensors
    if (room->dhtSensor != NULL) {
        room->temperature = room->dhtSensor->readTemperature();
        room->humidity = room->dhtSensor->readHumidity();
        
        if (isnan(room->temperature) || isnan(room->humidity)) {
            room->temperature = 25.0;
            room->humidity = 50.0;
            room->sensorError = true;
        } else {
            room->sensorError = false;
        }
    } else {
        room->temperature = 0;
        room->humidity = 0;
    }
    
    room->current = readCurrent(room->currentPin);
    bool motionDetected = checkMotion(room);
    
    // MANUAL MODE
    if (room->mode == "manual") {
        ledcWrite(room->mosfetPin, room->manualLightOn ? room->manualBrightness : 0);
        room->bulbActive = room->manualLightOn;
        
        if (room->fanPin != -1) {
            digitalWrite(room->fanPin, room->manualFanOn ? HIGH : LOW);
            room->fanActive = room->manualFanOn;
        }
        return;
    }
    
    // AUTO MODE - LIGHT CONTROL
    if (motionDetected) {
        if (!room->bulbActive && isDark) {
            Serial.println("💡 " + room->name + " LIGHT ON");
        }
        room->bulbActive = true;
        room->bulbOffTime = now + TIMER_DURATION;
    }
    
    if (room->bulbActive) {
        if (isDark) {
            int newBrightness = calculateBrightness(lightLevel);
            
            if (abs(newBrightness - room->brightness) > 5) {
                room->brightness = newBrightness;
                ledcWrite(room->mosfetPin, room->brightness);
            } else {
                ledcWrite(room->mosfetPin, room->brightness);
            }
        } else {
            if (room->brightness > 0) {
                Serial.println("💡 " + room->name + " LIGHT OFF (Bright)");
            }
            ledcWrite(room->mosfetPin, 0);
            room->brightness = 0;
        }
    }
    
    if (now > room->bulbOffTime && room->bulbOffTime != 0) {
        Serial.println("💡 " + room->name + " LIGHT OFF (Timer)");
        ledcWrite(room->mosfetPin, 0);
        room->bulbActive = false;
        room->bulbOffTime = 0;
        room->brightness = 0;
    }
    
    // AUTO MODE - FAN CONTROL
    if (room->fanPin != -1) {
        bool isHot = false;
        bool hasActiveTimer = false;
        
        if (room->dhtSensor != NULL) {
            isHot = (room->temperature > HOT_THRESHOLD);
            hasActiveTimer = (room->bulbOffTime > 0 && now < room->bulbOffTime);
            
            if (hasActiveTimer && isHot) {
                if (!room->fanActive) {
                    Serial.println("🌀 " + room->name + " FAN ON");
                }
                digitalWrite(room->fanPin, HIGH);
                room->fanActive = true;
                room->fanOffTime = room->bulbOffTime;
            }
            
            if ((!hasActiveTimer || !isHot) && room->fanActive) {
                Serial.println("🌀 " + room->name + " FAN OFF");
                digitalWrite(room->fanPin, LOW);
                room->fanActive = false;
                room->fanOffTime = 0;
            }
            
        } else {
            if (motionDetected) {
                if (!room->fanActive) {
                    Serial.println("🌀 " + room->name + " FAN ON");
                }
                digitalWrite(room->fanPin, HIGH);
                room->fanActive = true;
                room->fanOffTime = now + TIMER_DURATION;
            }
            
            if (now > room->fanOffTime && room->fanOffTime != 0) {
                Serial.println("🌀 " + room->name + " FAN OFF");
                digitalWrite(room->fanPin, LOW);
                room->fanActive = false;
                room->fanOffTime = 0;
            }
        }
    }
}

// ============================================
// BATCHED SENSOR DATA UPLOAD
// ============================================
void sendSensorDataBatched() {
    if (!wifiConnected) return;
    
    Room* room = &rooms[currentUploadRoom];
    int lightLevel = analogRead(LDR_PIN);
    
    HTTPClient http;
    http.begin(String(SERVER_INGEST_URL) + "?action=update_sensors");
    http.addHeader("Content-Type", "application/json");
    http.setTimeout(3000);
    
    StaticJsonDocument<300> doc;
    doc["device_id"] = DEVICE_ID;
    doc["room"] = room->name;
    doc["temperature"] = room->temperature;
    doc["humidity"] = room->humidity;
    doc["motion_detected"] = room->motionDetected;
    doc["light_level"] = lightLevel;
    doc["current"] = room->current;
    
    String payload;
    serializeJson(doc, payload);
    
    int httpCode = http.POST(payload);
    
    if (httpCode == 200) {
        successfulUploads++;
        sendDeviceStatusQuick(room);
    } else {
        failedUploads++;
    }
    
    http.end();
    
    currentUploadRoom = (currentUploadRoom + 1) % 3;
}

// ============================================
// QUICK DEVICE STATUS UPDATE
// ============================================
void sendDeviceStatusQuick(Room* room) {
    HTTPClient http;
    http.begin(String(SERVER_INGEST_URL) + "?action=update_device_status");
    http.addHeader("Content-Type", "application/json");
    http.setTimeout(2000);
    
    StaticJsonDocument<256> doc;
    doc["device_id"] = DEVICE_ID;
    doc["room"] = room->name;
    doc["device_type"] = "light";
    doc["status"] = room->bulbActive;
    doc["brightness"] = room->brightness;
    doc["mode"] = room->mode;
    
    String payload;
    serializeJson(doc, payload);
    http.POST(payload);
    http.end();
    
    if (room->fanPin != -1) {
        http.begin(String(SERVER_INGEST_URL) + "?action=update_device_status");
        http.addHeader("Content-Type", "application/json");
        http.setTimeout(2000);
        
        doc.clear();
        doc["device_id"] = DEVICE_ID;
        doc["room"] = room->name;
        doc["device_type"] = "fan";
        doc["status"] = room->fanActive;
        doc["mode"] = room->mode;
        
        serializeJson(doc, payload);
        http.POST(payload);
        http.end();
    }
}

// ============================================
// CHECK COMMANDS
// ============================================
void checkCommands() {
    if (!wifiConnected) return;
    
    HTTPClient http;
    http.begin(String(SERVER_DISPATCH_URL) + "?action=get_commands");
    http.setTimeout(3000);
    
    int httpCode = http.GET();
    
    if (httpCode == 200) {
        String response = http.getString();
        
        StaticJsonDocument<1024> doc;
        if (deserializeJson(doc, response) == DeserializationError::Ok && doc.containsKey("commands")) {
            JsonArray commands = doc["commands"];
            
            for (JsonObject cmd : commands) {
                String cmdRoom = cmd["room"];
                String deviceType = cmd["device_type"];
                String action = cmd["action"];
                String mode = cmd["mode"];
                int value = cmd["value"] | 255;
                
                for (int i = 0; i < 3; i++) {
                    if (rooms[i].name == cmdRoom) {
                        rooms[i].mode = mode;
                        
                        if (deviceType == "light") {
                            rooms[i].manualLightOn = (action == "on");
                            rooms[i].manualBrightness = value;
                        } else if (deviceType == "fan") {
                            rooms[i].manualFanOn = (action == "on");
                        }
                        
                        Serial.println("CMD: " + cmdRoom + " " + deviceType + " " + action);
                        break;
                    }
                }
            }
        }
    }
    
    http.end();
}

// ============================================
// SEND SYSTEM STATUS
// ============================================
void sendSystemStatus() {
    if (!wifiConnected) {
        Serial.println("⚠️ Cannot send system status - no WiFi");
        return;
    }
    
    HTTPClient http;
    http.begin(String(SERVER_INGEST_URL) + "?action=update_system_status");
    http.addHeader("Content-Type", "application/json");
    http.setTimeout(3000);
    
    StaticJsonDocument<128> doc;
    doc["device_id"] = DEVICE_ID;
    doc["status"] = systemON ? 1 : 0;
    
    String payload;
    serializeJson(doc, payload);
    
    int httpCode = http.POST(payload);
    
    Serial.println("📤 System status update: " + String(systemON ? "ON" : "OFF") + 
                   " (HTTP: " + String(httpCode) + ")");
    
    if (httpCode == 200) {
        Serial.println("✅ System status updated in database");
    } else {
        Serial.println("❌ System status update failed");
    }
    
    http.end();
}

// ============================================
// PRINT SYSTEM STATUS
// ============================================
void printSystemStatus() {
    Serial.println("\n========== STATUS ==========");
    Serial.println("System: " + String(systemON ? "ON" : "OFF"));
    Serial.println("WiFi: " + String(wifiConnected ? "OK" : "FAIL") + " (" + String(WiFi.RSSI()) + "dBm)");
    Serial.println("Uploads: " + String(successfulUploads) + " / " + String(failedUploads) + " fail");
    Serial.println("Config: " + String(configLoaded ? "LOADED" : "NOT LOADED"));
    
    Serial.println("\n--- THRESHOLDS ---");
    Serial.println("Temp: >" + String(HOT_THRESHOLD) + "°C");
    Serial.println("Dark: <" + String(DARKNESS_THRESHOLD));
    Serial.println("Timeout: " + String(TIMER_DURATION / 1000) + "s");
    
    int lightLevel = analogRead(LDR_PIN);
    Serial.println("\nLight: " + String(lightLevel) + (lightLevel < DARKNESS_THRESHOLD ? " DARK" : " BRIGHT"));
    
    for (int i = 0; i < 3; i++) {
        Serial.println("\n" + rooms[i].name + ":");
        if (rooms[i].dhtSensor) {
            Serial.println("  T:" + String(rooms[i].temperature) + "°C H:" + String(rooms[i].humidity) + "%");
        }
        Serial.println("  Motion:" + String(rooms[i].motionDetected ? "YES" : "NO"));
        Serial.println("  Light:" + String(rooms[i].bulbActive ? "ON" : "OFF"));
        if (rooms[i].fanPin != -1) {
            Serial.println("  Fan:" + String(rooms[i].fanActive ? "ON" : "OFF"));
        }
        Serial.println("  Current:" + String(rooms[i].current, 2) + "A");
    }
    
    Serial.println("============================\n");
}
