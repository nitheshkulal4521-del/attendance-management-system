<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "db.php";


/* =========================================================
   READ LOGIN DATA
   ========================================================= */

$data = json_decode(
    file_get_contents("php://input"),
    true
) ?: $_POST;

$usn = strtoupper(
    trim($data["usn"] ?? "")
);

$password = $data["password"] ?? "";


/* =========================================================
   VALIDATION
   ========================================================= */

if ($usn === "" || $password === "") {

    echo json_encode([
        "success" => false,
        "message" => "USN and password are required."
    ]);

    exit();
}


/* =========================================================
   FIND STUDENT ACCOUNT
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        u.user_id,
        u.username,
        u.password,

        s.student_id,
        s.roll_no,
        s.student_name,
        s.semester,
        s.department

    FROM users u

    INNER JOIN students s
        ON s.user_id = u.user_id

    WHERE UPPER(u.username) = ?
      AND u.role = 'student'

    LIMIT 1
");

$stmt->bind_param(
    "s",
    $usn
);

$stmt->execute();

$result = $stmt->get_result();


/* =========================================================
   ACCOUNT NOT FOUND
   ========================================================= */

if ($result->num_rows === 0) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Student account not found. Please create an account first."
    ]);

    $stmt->close();
    $conn->close();

    exit();
}


$student = $result->fetch_assoc();

$stmt->close();


/* =========================================================
   VERIFY PASSWORD
   ========================================================= */

if (
    !password_verify(
        $password,
        $student["password"]
    )
) {

    echo json_encode([
        "success" => false,
        "message" => "Incorrect password."
    ]);

    $conn->close();

    exit();
}


/* =========================================================
   LOGIN SUCCESS
   ========================================================= */

echo json_encode([

    "success" => true,

    "message" =>
        "Login successful.",

    "user" => [

        "id" =>
            (int)$student["student_id"],

        "user_id" =>
            (int)$student["user_id"],

        "usn" =>
            $student["roll_no"],

        "name" =>
            $student["student_name"],

        "department" =>
            $student["department"],

        "semester" =>
            (int)$student["semester"]

    ]

]);


$conn->close();

?>