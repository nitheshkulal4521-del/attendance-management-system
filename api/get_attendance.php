<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "db.php";

$student_id = (int)($_GET["student_id"] ?? 0);
$limit = (int)($_GET["limit"] ?? 0);
$status = trim($_GET["status"] ?? "");
if ($student_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "student_id is required."
    ]);
    exit();
}


/* =========================================================
   GET ATTENDANCE + REASON/PROOF + GRACE
   ========================================================= */

$sql = "
    SELECT
        a.attendance_id,
        a.session_id,

        s.subject_name,
        cs.class_date AS date,

        CASE
            WHEN a.status = 'Present'
                 OR ga.status = 'Granted'
            THEN 'Present'
            ELSE 'Absent'
        END AS status,

        CASE
            WHEN ga.status = 'Granted'
            THEN 1
            ELSE 0
        END AS grace,

        ar.reason_id AS proof_id,
        ar.reason AS absence_reason,
        ar.proof_file,
        ar.status AS proof_status

    FROM attendance a

    INNER JOIN class_sessions cs
        ON cs.session_id = a.session_id

    INNER JOIN subjects s
        ON s.subject_id = cs.subject_id

    LEFT JOIN grace_attendance ga
        ON ga.attendance_id = a.attendance_id
       AND ga.status = 'Granted'

    LEFT JOIN absence_reasons ar
        ON ar.student_id = a.student_id
       AND ar.session_id = a.session_id

    WHERE a.student_id = ?
";

if ($status === "Absent") {

    $sql .= "
        AND a.status = 'Absent'
        AND (
            ga.status IS NULL
            OR ga.status <> 'Granted'
        )
    ";

}

$sql .= "
    ORDER BY
        cs.class_date DESC,
        a.attendance_id DESC
";

/* Optional limit for dashboard */

if ($limit > 0) {
    $limit = min($limit, 100);
    $sql .= " LIMIT " . $limit;
}


$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();

$result = $stmt->get_result();

$records = [];


while ($row = $result->fetch_assoc()) {

    $records[] = [

        "attendance_id" =>
            (int)$row["attendance_id"],

        "session_id" =>
            (int)$row["session_id"],

        "subject_name" =>
            $row["subject_name"],

        "date" =>
            $row["date"],

        "status" =>
            $row["status"],

        "grace" =>
            (int)$row["grace"],

        "proof_id" =>
            $row["proof_id"] !== null
                ? (int)$row["proof_id"]
                : null,

        "proof_status" =>
            $row["proof_status"],

        "reason" =>
            $row["absence_reason"],

        "proof_file" =>
            $row["proof_file"]
    ];
}


$stmt->close();


echo json_encode([
    "success" => true,
    "records" => $records
]);


$conn->close();

?>