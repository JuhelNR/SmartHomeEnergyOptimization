<?php

require "../vendor/autoload.php";

//ERROR LOG FILE PATH CONFIG
$logDir = "../monitoring";
$logFile = $logDir . "/api_dispatch.log";

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


if($_SERVER["REQUEST_METHOD"] !== "POST") {

    logError("INVALID REQUEST METHOD USED: " . $_SERVER["REQUEST_METHOD"]);
    echo "!Restricted access";
    header("location: ../webApp/index.php");

}
else{

    try{

        //QUERY DATABSE
        $result = conn->executeQuery("SELECT * FROM readings ORDER BY id DESC LIMIT 1");
        $row = $result->fetch(PDO::FETCH_ASSOC);

        //IF DATA FETCH FAILS
        if (!$row) {
            logError("NO RECORDS FOUND IN readings TABLE.");
            echo json_encode(["error" => "No data available"]);
            exit();
        }

            else{

                //RETURN RESULT AS JSON FILE
                echo json_encode($row);

                //CLOSE CONNECTION
                conn->closeConnection();
            }

    }

    catch(Exception $e) {
        //LOG EXCEPTION ERROR
        logError("EXCEPTION: " . $e->getMessage());
        echo json_encode(["error" => "Server error occurred"]);
        exit();
    }
}
