#include <WiFi.h>
#include <HTTPClient.h>

const char* ssid = "WIFI_NAME";       // TODO: Change this
const char* password = "WIFI_PASS";   // TODO: Change this

void setup() {
    Serial.begin(9600);

    WiFi.begin(ssid, password);

    int attempts = 0;

    while (WiFi.status() != WL_CONNECTED && attempts < 5) {
        delay(500);
        Serial.println("Connecting to WiFi...");
        attempts++;
    }

    if (WiFi.status() == WL_CONNECTED) {
        Serial.println("WiFi connection established!");
        Serial.print("IP Address: ");
        Serial.println(WiFi.localIP());
    } else {
        Serial.println("WiFi connection failed after 5 attempts.");
        Serial.println("Please check credentials in Arduino/smart_home_node.ino.");
        return; // Stop setup, ESP won't try to send data
    }
}

void loop() {
    if (WiFi.status() == WL_CONNECTED) {

        // Example sensor data — replace with real variables
        float temperature = 25.3;
        float humidity = 60.2;
        int motion = 1;

        HTTPClient http;
        http.begin("http://your-server-ip/capstone/serverAPI/api_ingest.php");
        http.addHeader("Content-Type", "application/x-www-form-urlencoded");

        String postData =
            "temp=" + String(temperature) +
            "&hum=" + String(humidity) +
            "&mot=" + String(motion);

        int httpCode = http.POST(postData);

        Serial.print("HTTP Response Code: ");
        Serial.println(httpCode);

        // Print response from server
        if (httpCode > 0) {
            String payload = http.getString();
            Serial.println("Server Response: " + payload);
        }

        http.end(); // VERY IMPORTANT
    }
    else {
        Serial.println("Lost WiFi connection!");
    }

    delay(5000); // Send every 5 seconds
}
