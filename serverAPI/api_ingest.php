<?php
require "../vendor/autoload.php";

//Check if the Page was loaded correctly
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "!Restricted access";
    header("location: ../webApp/index.php");
}

else{

    if(ISSET($_POST["Temp"]) || ISSET($_POST["Hum"]) || ISSET($_POST["mot"])){

        $temp = $_POST["Temp"];
        $hum = $_POST["Hum"];
        $mot = $_POST["mot"];

        //SANITIZE AND INSERT READINGS INTO DATABASE
        if ($temp == "" || $hum == "" || $mot == "") {
            $stmt = conn -> prepare("INSERT into readings (temp, hum, mot) values (?, ?, ?)");
            $stmt->bind_param("sss", $temp, $hum, $mot);
            $stmt->execute();
            $stmt->close();
        }
        else{
            http_request(400);
            echo"Missing Data";
        }
    }

    //CLOSE CONNECTION
    conn->closeConnection();

}