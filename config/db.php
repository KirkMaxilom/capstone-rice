<?php
define('BASE_URL', '/capstone-rice/');

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "rice_inventory";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed");
}
?>
