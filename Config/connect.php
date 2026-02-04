error_reporting(E_ALL);
ini_set('display_errors', 1);


$host = "Localhost";
$user =  "root";
$pass = "Maya@22";
$dbname = "capstone";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error;
}
