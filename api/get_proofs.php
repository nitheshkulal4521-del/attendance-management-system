<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "db.php";


$student_id = (int)($_GET["student_id"] ?? 0);


if ($student_id <= 0) {

    echo json_encode([
        "success" => false,
        "message" => "student_id is required."
    ]);

    exit();
}


/* =========================================================
   GET STUDENT PROOF SUBMISSIONS
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        ar.reason_id AS proof_id,
        ar.student_id,
        ar.session_id,
        ar.reason,
        ar.proof_file,
        ar.status,

        cs.class_date AS date,

        s.subject_name

    FROM absence_reasons ar

    INNER JOIN class_sessions cs
        ON cs.session_id = ar.session_id

    INNER JOIN subjects s
        ON s.subject_id = cs.subject_id

    WHERE ar.student_id = ?

    ORDER BY
        ar.reason_id DESC
");


$stmt->bind_param(
    "i",
    $student_id
);

$stmt->execute();

$result = $stmt->get_result();

$proofs = [];


while ($row = $result->fetch_assoc()) {

    /*
       reason currently contains:

       Medical Certificate
       Fever and doctor advised rest

       First line = proof type
       Remaining text = remarks
    */

    $reason = trim(
        $row["reason"] ?? ""
    );


    $parts = preg_split(
        "/\r\n|\n|\r/",
        $reason,
        2
    );


    $type_label =
        trim($parts[0] ?? "");

    $remarks =
        trim($parts[1] ?? "");


    /* Convert database label back to frontend value */

    switch ($type_label) {

        case "Medical Certificate":
            $proof_type = "medical";
            break;

        case "Leave Application":
            $proof_type = "leave";
            break;

        case "Emergency / Family":
            $proof_type = "emergency";
            break;

        default:
            $proof_type = "other";
            break;
    }


    $proofs[] = [

        "proof_id" =>
            (int)$row["proof_id"],

        "student_id" =>
            (int)$row["student_id"],

        "session_id" =>
            (int)$row["session_id"],

        "subject_name" =>
            $row["subject_name"],

        "date" =>
            $row["date"],

        "proof_type" =>
            $proof_type,

        "remarks" =>
            $remarks,

        "proof_file" =>
            $row["proof_file"],

        "status" =>
            $row["status"]

    ];
}


$stmt->close();


echo json_encode([

    "success" => true,

    "proofs" => $proofs

]);


$conn->close();

?>