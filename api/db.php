<?php

require_once __DIR__ . "/../config.php";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $db_name
);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8");

?>