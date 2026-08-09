<?php

require_once __DIR__ . "/config.php";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $db_name
);

if ($conn->connect_error) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "DB connection failed: " . $conn->connect_error
    ]);

    exit();
}

$conn->set_charset("utf8");

?>