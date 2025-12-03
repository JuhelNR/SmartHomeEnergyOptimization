<?php

$host = "Localhost";
$user =  "root";
$pass = "";
$dbname = "capstone";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error;
}
