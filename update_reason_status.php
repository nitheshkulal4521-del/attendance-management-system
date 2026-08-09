<?php

session_start();

header("Content-Type: application/json");

require_once "db_connect.php";


if (!isset($_SESSION['teacher_id'])) {

    echo json_encode([
        "success" => false,
        "message" => "Unauthorized."
    ]);

    exit();
}


$data = json_decode(
    file_get_contents("php://input"),
    true
);


$reason_id =
    (int)($data["reason_id"] ?? 0);

$status =
    trim($data["status"] ?? "");


if (
    $reason_id <= 0 ||
    !in_array(
        $status,
        ["Approved", "Rejected"],
        true
    )
) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid request."
    ]);

    exit();
}


/* GET TEACHER */

$user_id =
    (int)$_SESSION["teacher_id"];


$stmt = $conn->prepare("
    SELECT teacher_id
    FROM teachers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$teacher =
    $stmt->get_result()->fetch_assoc();

$stmt->close();


if (!$teacher) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Teacher account not found."
    ]);

    exit();
}


$teacher_id =
    (int)$teacher["teacher_id"];


/* VERIFY REASON BELONGS TO THIS TEACHER */

$stmt = $conn->prepare("
    SELECT
        ar.reason_id

    FROM absence_reasons ar

    INNER JOIN class_sessions cs
        ON cs.session_id = ar.session_id

    INNER JOIN classes c
        ON c.class_id = cs.class_id

    WHERE ar.reason_id = ?
      AND c.teacher_id = ?

    LIMIT 1
");


$stmt->bind_param(
    "ii",
    $reason_id,
    $teacher_id
);

$stmt->execute();

$result =
    $stmt->get_result();


if ($result->num_rows === 0) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Reason not found or access denied."
    ]);

    $stmt->close();
    $conn->close();

    exit();
}


$stmt->close();


/* UPDATE STATUS */

$stmt = $conn->prepare("
    UPDATE absence_reasons
    SET status = ?
    WHERE reason_id = ?
      AND status = 'Pending'
");


$stmt->bind_param(
    "si",
    $status,
    $reason_id
);

$stmt->execute();


if ($stmt->affected_rows !== 1) {

    echo json_encode([
        "success" => false,
        "message" =>
            "This submission has already been reviewed."
    ]);

    $stmt->close();
    $conn->close();

    exit();
}


$stmt->close();


echo json_encode([
    "success" => true,
    "message" =>
        "Submission " .
        strtolower($status) .
        " successfully."
]);


$conn->close();

?>