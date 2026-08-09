<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "db.php";


/* =========================================================
   INPUT
   ========================================================= */

$student_id =
    (int)($_POST["student_id"] ?? 0);

$attendance_id =
    (int)($_POST["attendance_id"] ?? 0);

$proof_type =
    trim($_POST["proof_type"] ?? "");

$remarks =
    trim($_POST["remarks"] ?? "");


if (
    $student_id <= 0 ||
    $attendance_id <= 0
) {

    echo json_encode([
        "success" => false,
        "message" => "Invalid attendance record."
    ]);

    exit();
}


if ($proof_type === "") {

    echo json_encode([
        "success" => false,
        "message" => "Please select a proof type."
    ]);

    exit();
}


/* =========================================================
   FILE REQUIRED
   ========================================================= */

if (
    !isset($_FILES["proof_file"]) ||
    $_FILES["proof_file"]["error"] !== UPLOAD_ERR_OK
) {

    echo json_encode([
        "success" => false,
        "message" => "Proof document is required."
    ]);

    exit();
}


/* =========================================================
   VERIFY ABSENT ATTENDANCE
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        a.attendance_id,
        a.session_id

    FROM attendance a

    WHERE a.attendance_id = ?
      AND a.student_id = ?
      AND a.status = 'Absent'

    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $attendance_id,
    $student_id
);

$stmt->execute();

$result = $stmt->get_result();


if ($result->num_rows === 0) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Absent attendance record not found."
    ]);

    $stmt->close();
    $conn->close();

    exit();
}


$attendance =
    $result->fetch_assoc();

$stmt->close();

$session_id =
    (int)$attendance["session_id"];


/* =========================================================
   CHECK GRACE
   ========================================================= */

$stmt = $conn->prepare("
    SELECT grace_id
    FROM grace_attendance
    WHERE attendance_id = ?
      AND status = 'Granted'
    LIMIT 1
");

$stmt->bind_param(
    "i",
    $attendance_id
);

$stmt->execute();

$grace_result =
    $stmt->get_result();


if ($grace_result->num_rows > 0) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Attendance has already been granted grace."
    ]);

    $stmt->close();
    $conn->close();

    exit();
}

$stmt->close();


/* =========================================================
   CHECK EXISTING SUBMISSION
   ========================================================= */

$stmt = $conn->prepare("
    SELECT reason_id
    FROM absence_reasons
    WHERE student_id = ?
      AND session_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $student_id,
    $session_id
);

$stmt->execute();

$existing =
    $stmt->get_result();


if ($existing->num_rows > 0) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Proof has already been submitted for this absence."
    ]);

    $stmt->close();
    $conn->close();

    exit();
}

$stmt->close();


/* =========================================================
   VALIDATE FILE
   ========================================================= */

$file =
    $_FILES["proof_file"];


if ($file["size"] > 5 * 1024 * 1024) {

    echo json_encode([
        "success" => false,
        "message" =>
            "File must be under 5 MB."
    ]);

    exit();
}


$extension =
    strtolower(
        pathinfo(
            $file["name"],
            PATHINFO_EXTENSION
        )
    );


$allowed = [
    "pdf",
    "jpg",
    "jpeg",
    "png",
    "doc",
    "docx"
];


if (!in_array($extension, $allowed, true)) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Unsupported file type."
    ]);

    exit();
}


/* =========================================================
   SAVE FILE
   ========================================================= */

$upload_directory =
    __DIR__ . "/../uploads/proofs/";


if (!is_dir($upload_directory)) {

    if (
        !mkdir(
            $upload_directory,
            0775,
            true
        )
    ) {

        echo json_encode([
            "success" => false,
            "message" =>
                "Unable to create upload directory."
        ]);

        exit();
    }
}


$stored_name =
    "proof_" .
    $student_id . "_" .
    $session_id . "_" .
    time() . "_" .
    bin2hex(random_bytes(4)) .
    "." .
    $extension;


$destination =
    $upload_directory .
    $stored_name;


if (
    !move_uploaded_file(
        $file["tmp_name"],
        $destination
    )
) {

    echo json_encode([
        "success" => false,
        "message" =>
            "Unable to save uploaded file."
    ]);

    exit();
}


/* =========================================================
   BUILD REASON
   ========================================================= */

/*
   absence_reasons doesn't have separate
   proof_type and remarks columns.

   Store both inside reason:

   Medical Certificate
   Fever and doctor advised rest
*/

$type_labels = [

    "medical" =>
        "Medical Certificate",

    "leave" =>
        "Leave Application",

    "emergency" =>
        "Emergency / Family",

    "other" =>
        "Other"
];


$type_label =
    $type_labels[$proof_type] ?? "Other";


$reason =
    $type_label;


if ($remarks !== "") {

    $reason .= "\n" . $remarks;
}


/* =========================================================
   INSERT ABSENCE REASON
   ========================================================= */

$status = "Pending";


$stmt = $conn->prepare("
    INSERT INTO absence_reasons
    (
        student_id,
        session_id,
        reason,
        proof_file,
        status
    )

    VALUES (?, ?, ?, ?, ?)
");


$stmt->bind_param(
    "iisss",
    $student_id,
    $session_id,
    $reason,
    $stored_name,
    $status
);


if (!$stmt->execute()) {

    if (file_exists($destination)) {
        unlink($destination);
    }

    echo json_encode([
        "success" => false,
        "message" =>
            "Unable to save proof submission."
    ]);

    $stmt->close();
    $conn->close();

    exit();
}


$reason_id =
    $conn->insert_id;


$stmt->close();


echo json_encode([

    "success" => true,

    "message" =>
        "Proof submitted successfully.",

    "reason_id" =>
        $reason_id

]);


$conn->close();

?>