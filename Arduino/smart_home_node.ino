#include <WiFi.h>
#include <HTTPClient.h>
#include <SPIFFS.h>

const char* ssid = "WIFI_NAME";             // TODO: CHANGE THIS TO WIFI NAME
const char* password = "WIFI_PASS";         // TODO: CHANGE THIS TO WIFI PASSWORD

// FUNCTION TO LOG MESSAGES INTO /log.txt
void logToFile(String message) {
    File logFile = SPIFFS.open("../monitoring/esp_post.log", FILE_APPEND);
    if (!logFile) {
        Serial.println("ERROR: Could not open log file!");
        return;
    }

    logFile.println(message);
    logFile.close();
}

bool getStatus(){

       //TODO: THIS WILL RETURN A GENERAL SYSTEM STATUS
       //RETURN 0(IF ALL SYSTEMS ARE OK) 1(IF ERRORS OCCURRED)
}

void setup() {
    Serial.begin(9600);

    // MOUNT SPIFFS FILE SYSTEM
    if (!SPIFFS.begin(true)) {
        Serial.println("ERROR: SPIFFS MOUNT FAILED");
        return;
    }

    logToFile("=== DEVICE BOOTED ===");

    WiFi.begin(ssid, password);

    int attempts = 0;

    // TRY CONNECTING 5 TIMES
    while (WiFi.status() != WL_CONNECTED && attempts < 5) {
        delay(500);
        Serial.println("Connecting to WiFi...");
        logToFile("Trying WiFi connection... attempt " + String(attempts + 1));
        attempts++;
    }

    if (WiFi.status() == WL_CONNECTED) {
        String ip = WiFi.localIP().toString();
        Serial.println("WiFi connected! IP: " + ip);
        logToFile("WiFi connected. IP: " + ip);
    } else {
        Serial.println("WiFi connection failed after 5 attempts.");
        logToFile("ERROR: WiFi connection FAILED after 5 attempts.");
        return; // STOP THE PROGRAM
    }
}

void loop() {
    if (WiFi.status() == WL_CONNECTED) {

        // DUMMY DATA (TODO: REPLACE WITH SENSOR DATA)
        String device_ID = WiFi.macAddress();
        float temperature = 25.3;
        float humidity = 60.2;
        int motion = 1;
        bool stat = getStatus();

        HTTPClient http;
        http.begin("http://your-server-ip/capstone/serverAPI/api_ingest.php");
        http.addHeader("Content-Type", "application/x-www-form-urlencoded");

        // DUMMY DATA POST
        String postData =
            "device_ID=" + device_ID +
            "&temp=" + String(temperature) +
            "&hum=" + String(humidity) +
            "&mot=" + String(motion)+
            "stat=" + String(stat);

        // SEND READINGS TO SERVER
        int httpCode = http.POST(postData);
        logToFile("POST SENT: " + postData);

        Serial.print("HTTP Response Code: ");
        Serial.println(httpCode);

        if (httpCode > 0) {
            String payload = http.getString();
            Serial.println("Server Response: " + payload);

            logToFile("SUCCESS: Server replied " + payload);
        } else {
            logToFile("ERROR: HTTP POST FAILED. CODE = " + String(httpCode));
        }

        http.end();
    }
    else {
        Serial.println("Lost WiFi connection!");
        logToFile("ERROR: Lost WiFi connection.");
    }

    delay(5000); // Send every 5 seconds
}
