<?php

require "../vendor/autoload.php";

if($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo "!Restricted access";
    header("location: ../webApp/index.php");
}
else{

    //QUERY DATABSE
    $result = conn->executeQuery("SELECT * FROM readings ORDER BY id DESC LIMIT 1");
    $row = $result->fetch(PDO::FETCH_ASSOC);

    //RETURN RESULT AS JSON FILE
    echo json_encode($row);

    //CLOSE CONNECTION
    conn->closeConnection();
}
