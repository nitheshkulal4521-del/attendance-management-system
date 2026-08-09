<?php

header("Content-Type: application/json");

require_once "db.php";

$data = json_decode(
    file_get_contents("php://input"),
    true
);

$name = trim($data["name"] ?? "");
$usn = strtoupper(trim($data["usn"] ?? ""));
$password = $data["password"] ?? "";
$confirm_password = $data["confirm_password"] ?? "";


/* ==========================================
   VALIDATION
   ========================================== */

if (
    $name === "" ||
    $usn === "" ||
    $password === "" ||
    $confirm_password === ""
) {

    echo json_encode([
        "success" => false,
        "message" => "All fields are required."
    ]);

    exit();
}


if ($password !== $confirm_password) {

    echo json_encode([
        "success" => false,
        "message" => "Passwords do not match."
    ]);

    exit();
}


if (strlen($password) < 6) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Password must contain at least 6 characters."
    ]);

    exit();
}


/* ==========================================
   FIND STUDENT USING USN
   ========================================== */

$stmt = $conn->prepare("
    SELECT
        student_id,
        student_name,
        user_id
    FROM students
    WHERE UPPER(roll_no) = ?
    LIMIT 1
");

$stmt->bind_param("s", $usn);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {

    echo json_encode([
        "success" => false,
        "message" =>
            "USN not found. Please contact your teacher."
    ]);

    exit();
}

$student = $result->fetch_assoc();
$stmt->close();


/* ==========================================
   CHECK NAME
   ========================================== */

$entered_name = strtolower(
    preg_replace('/\s+/', ' ', trim($name))
);

$database_name = strtolower(
    preg_replace(
        '/\s+/',
        ' ',
        trim($student["student_name"])
    )
);


if ($entered_name !== $database_name) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Name does not match the student record."
    ]);

    exit();
}


/* ==========================================
   CHECK ACCOUNT ALREADY EXISTS
   ========================================== */

if (!empty($student["user_id"])) {

    echo json_encode([
        "success" => false,
        "message" =>
            "An account already exists for this USN. Please sign in."
    ]);

    exit();
}


/* ==========================================
   CREATE USER ACCOUNT
   ========================================== */

$password_hash =
    password_hash(
        $password,
        PASSWORD_DEFAULT
    );


$conn->begin_transaction();

try {

    $role = "student";


    $stmt = $conn->prepare("
        INSERT INTO users
        (
            username,
            password,
            role
        )
        VALUES (?, ?, ?)
    ");

    $stmt->bind_param(
        "sss",
        $usn,
        $password_hash,
        $role
    );

    $stmt->execute();


    $user_id = $conn->insert_id;

    $stmt->close();


    /* Connect account to student */

    $student_id =
        (int)$student["student_id"];


    $stmt = $conn->prepare("
        UPDATE students
        SET user_id = ?
        WHERE student_id = ?
          AND user_id IS NULL
    ");

    $stmt->bind_param(
        "ii",
        $user_id,
        $student_id
    );

    $stmt->execute();


    if ($stmt->affected_rows !== 1) {

        throw new Exception(
            "Unable to link student account."
        );
    }

    $stmt->close();


    $conn->commit();


    echo json_encode([
        "success" => true,
        "message" =>
            "Account created successfully. You can now sign in."
    ]);


} catch (Throwable $e) {

    $conn->rollback();


    echo json_encode([
        "success" => false,
        "message" =>
            "Unable to create account."
    ]);
}


$conn->close();

?>