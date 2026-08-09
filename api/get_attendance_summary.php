<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "db.php";


/* =========================================================
   GET STUDENT ID
   ========================================================= */

$student_id = (int)($_GET["student_id"] ?? 0);

if ($student_id <= 0) {

    echo json_encode([
        "success" => false,
        "message" => "student_id is required."
    ]);

    exit();
}


/* =========================================================
   VERIFY STUDENT
   ========================================================= */

$stmt = $conn->prepare("
    SELECT student_id
    FROM students
    WHERE student_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $student_id);
$stmt->execute();

$student_result = $stmt->get_result();

if ($student_result->num_rows === 0) {

    echo json_encode([
        "success" => false,
        "message" => "Student not found."
    ]);

    $stmt->close();
    $conn->close();

    exit();
}

$stmt->close();


/* =========================================================
   OVERALL ATTENDANCE
   =========================================================
   
   Effective Present =
   actual Present OR granted grace
   
   ========================================================= */

$stmt = $conn->prepare("
    SELECT

        COUNT(
            DISTINCT a.session_id
        ) AS total_classes,

        COUNT(
            DISTINCT CASE

                WHEN
                    a.status = 'Present'
                    OR ga.status = 'Granted'

                THEN a.session_id

            END
        ) AS attended,

        COUNT(
            DISTINCT CASE

                WHEN
                    a.status = 'Absent'
                    AND (
                        ga.status IS NULL
                        OR ga.status <> 'Granted'
                    )

                THEN a.session_id

            END
        ) AS absent,

        COUNT(
            DISTINCT CASE

                WHEN ga.status = 'Granted'

                THEN a.session_id

            END
        ) AS grace

    FROM attendance a

    LEFT JOIN grace_attendance ga
        ON ga.attendance_id = a.attendance_id
       AND ga.status = 'Granted'

    WHERE a.student_id = ?
");

$stmt->bind_param(
    "i",
    $student_id
);

$stmt->execute();

$overall =
    $stmt->get_result()->fetch_assoc();

$stmt->close();


$total_classes =
    (int)($overall["total_classes"] ?? 0);

$attended =
    (int)($overall["attended"] ?? 0);

$absent =
    (int)($overall["absent"] ?? 0);

$grace =
    (int)($overall["grace"] ?? 0);


$percentage =
    $total_classes > 0
        ? round(
            ($attended / $total_classes) * 100,
            1
        )
        : 0;


/* =========================================================
   SUBJECT-WISE ATTENDANCE
   ========================================================= */

$stmt = $conn->prepare("
    SELECT

        s.subject_id,

        s.subject_name,

        COUNT(
            DISTINCT a.session_id
        ) AS total,

        COUNT(
            DISTINCT CASE

                WHEN
                    a.status = 'Present'
                    OR ga.status = 'Granted'

                THEN a.session_id

            END
        ) AS present,

        COUNT(
            DISTINCT CASE

                WHEN
                    a.status = 'Absent'
                    AND (
                        ga.status IS NULL
                        OR ga.status <> 'Granted'
                    )

                THEN a.session_id

            END
        ) AS absent,

        COUNT(
            DISTINCT CASE

                WHEN ga.status = 'Granted'

                THEN a.session_id

            END
        ) AS grace

    FROM attendance a

    INNER JOIN class_sessions cs
        ON cs.session_id = a.session_id

    INNER JOIN subjects s
        ON s.subject_id = cs.subject_id

    LEFT JOIN grace_attendance ga
        ON ga.attendance_id = a.attendance_id
       AND ga.status = 'Granted'

    WHERE a.student_id = ?

    GROUP BY
        s.subject_id,
        s.subject_name

    ORDER BY
        s.subject_name
");

$stmt->bind_param(
    "i",
    $student_id
);

$stmt->execute();

$result =
    $stmt->get_result();


$subjects = [];


while ($row = $result->fetch_assoc()) {

    $subject_total =
        (int)$row["total"];

    $subject_present =
        (int)$row["present"];

    $subject_absent =
        (int)$row["absent"];

    $subject_grace =
        (int)$row["grace"];


    $subject_percentage =
        $subject_total > 0
            ? round(
                (
                    $subject_present /
                    $subject_total
                ) * 100,
                1
            )
            : 0;


    $subjects[] = [

        "subject_id" =>
            (int)$row["subject_id"],

        "subject_name" =>
            $row["subject_name"],

        "total" =>
            $subject_total,

        "present" =>
            $subject_present,

        "absent" =>
            $subject_absent,

        "grace" =>
            $subject_grace,

        "percentage" =>
            $subject_percentage

    ];
}

$stmt->close();


/* =========================================================
   RESPONSE
   ========================================================= */

echo json_encode([

    "success" => true,

    "summary" => [

        "total_classes" =>
            $total_classes,

        "attended" =>
            $attended,

        "absent" =>
            $absent,

        "grace" =>
            $grace,

        "percentage" =>
            $percentage,

        "subjects" =>
            $subjects

    ]

]);


$conn->close();

?>