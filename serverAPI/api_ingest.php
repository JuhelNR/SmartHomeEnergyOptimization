<?php
require "../vendor/autoload.php";

//ERROR LOG FILE PATH CONFIG
$logDir = "../monitoring";
$logFile = $logDir . "/api_ingest.log";

//CREATE DIRECTORY IS IT DOESN'T EXIST
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}


//HELPER FUNCTION TO LOG MESSAGES INTO FILE
function logError($message) {
    global $logFile;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

//CHECK IF PAGE WAS LOADED CORRECTLY
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logError("INVALID REQUEST METHOD USED: " . $_SERVER["REQUEST_METHOD"]);
    echo "!Restricted access";
    header("location: ../webApp/index.php");

}

else{

    try {

        if(ISSET($_POST["device_ID"]) || ISSET($_POST["Temp"]) || ISSET($_POST["Hum"]) || ISSET($_POST["mot"]) || ISSET($_POST["stat"])){

            $device_ID = $_POST["device_ID"];
            $temp = $_POST["Temp"];
            $hum = $_POST["Hum"];
            $mot = $_POST["mot"];

            //SANITIZE AND INSERT READINGS INTO DATABASE
            if ($device_ID == "" || $temp == "" || $hum == "" || $mot == "") {

                $stmt = conn -> prepare("INSERT into readings (temp, hum, mot) values (?, ?, ?)");
                $stmt->bind_param("sss", $temp, $hum, $mot);
                $stmt->execute();
                $stmt->close();
                logError("DATA ENTRY INTO readings TABLE: " . $device_ID . " | " . $temp . " | " . $hum);
            }
            else{
                http_request(400);
                echo"Missing Data";
                logError("MISSING DATA");
            }
        }

        //CLOSE CONNECTION
        conn->closeConnection();

    }

    catch (Exception $e) {
        //LOG EXCEPTION ERROR
        logError("EXCEPTION: " . $e->getMessage());
        echo json_encode(["error" => "Server error occurred"]);
        exit();
    }


}