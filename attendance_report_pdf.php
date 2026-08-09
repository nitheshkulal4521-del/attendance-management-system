<?php

session_start();
require 'db_connect.php';

if (!isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login.php");
    exit();
}

$user_id = (int)$_SESSION['teacher_id'];


/* =========================================================
   GET TEACHER
   ========================================================= */

$stmt = $conn->prepare("
    SELECT teacher_id, teacher_name
    FROM teachers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_id   = (int)$teacher['teacher_id'];
$teacher_name = $teacher['teacher_name'];


/* =========================================================
   FILTERS
   ========================================================= */

$class_id  = (int)($_GET['class_id'] ?? 0);
$from_date = trim($_GET['from_date'] ?? '');
$to_date   = trim($_GET['to_date'] ?? '');
$status_filter = $_GET['status'] ?? 'all';

if ($class_id <= 0) {
    die("Invalid class selected.");
}


/* =========================================================
   VERIFY CLASS
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        c.class_id,
        c.class_name,
        c.department,
        c.semester,
        c.academic_year,
        s.subject_name

    FROM classes c

    LEFT JOIN class_subjects cs
        ON cs.class_id = c.class_id

    LEFT JOIN subjects s
        ON s.subject_id = cs.subject_id

    WHERE c.class_id = ?
      AND c.teacher_id = ?

    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $class_id,
    $teacher_id
);

$stmt->execute();

$class =
    $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$class) {
    die("Class not found.");
}


/* =========================================================
   DATE CONDITIONS
   ========================================================= */

$date_conditions = '';

if ($from_date !== '') {
    $date_conditions .= "
        AND sess.class_date >= ?
    ";
}

if ($to_date !== '') {
    $date_conditions .= "
        AND sess.class_date <= ?
    ";
}


/* =========================================================
   ATTENDANCE REPORT
   ========================================================= */

$sql = "
    SELECT
        st.student_id,
        st.roll_no,
        st.student_name,

        COUNT(
            DISTINCT sess.session_id
        ) AS total_classes,

        COUNT(
            DISTINCT CASE
                WHEN
                    a.status = 'Present'
                    OR ga.status = 'Granted'
                THEN sess.session_id
            END
        ) AS effective_present,

        COUNT(
            DISTINCT CASE
                WHEN
                    a.status = 'Absent'
                    AND (
                        ga.status IS NULL
                        OR ga.status <> 'Granted'
                    )
                THEN sess.session_id
            END
        ) AS absent_count,

        COUNT(
            DISTINCT CASE
                WHEN ga.status = 'Granted'
                THEN sess.session_id
            END
        ) AS grace_count

    FROM class_students cst

    INNER JOIN students st
        ON st.student_id = cst.student_id

    LEFT JOIN class_sessions sess
        ON sess.class_id = cst.class_id
        $date_conditions

    LEFT JOIN attendance a
        ON a.session_id = sess.session_id
       AND a.student_id = st.student_id

    LEFT JOIN grace_attendance ga
        ON ga.attendance_id = a.attendance_id
       AND ga.status = 'Granted'

    WHERE cst.class_id = ?

    GROUP BY
        st.student_id,
        st.roll_no,
        st.student_name

    ORDER BY st.roll_no
";


$types = '';
$params = [];

if ($from_date !== '') {
    $types .= 's';
    $params[] = $from_date;
}

if ($to_date !== '') {
    $types .= 's';
    $params[] = $to_date;
}

$types .= 'i';
$params[] = $class_id;


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$result = $stmt->get_result();

$report = [];


while ($row = $result->fetch_assoc()) {

    $total =
        (int)$row['total_classes'];

    $present =
        (int)$row['effective_present'];

    $absent =
        (int)$row['absent_count'];

    $grace =
        (int)$row['grace_count'];


    $percentage =
        $total > 0
            ? round(
                ($present / $total) * 100,
                1
            )
            : 0;


    /* Same status filter as main report */

    $include = true;

    if (
        $status_filter === 'below75' &&
        $percentage >= 75
    ) {
        $include = false;
    }

    if (
        $status_filter === '75to85' &&
        (
            $percentage < 75 ||
            $percentage >= 85
        )
    ) {
        $include = false;
    }

    if (
        $status_filter === 'above85' &&
        $percentage < 85
    ) {
        $include = false;
    }


    if ($include) {

        $report[] = [
            'roll_no' =>
                $row['roll_no'],

            'student_name' =>
                $row['student_name'],

            'total' =>
                $total,

            'present' =>
                $present,

            'absent' =>
                $absent,

            'grace' =>
                $grace,

            'percentage' =>
                $percentage
        ];
    }
}

$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>Attendance Report</title>

<style>

* {
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    margin: 0;
    background: #f5f5f5;
    color: #111827;
}

.report {
    width: 100%;
    max-width: 1100px;
    margin: 30px auto;
    background: white;
    padding: 35px;
}

.header {
    text-align: center;
    border-bottom: 2px solid #111827;
    padding-bottom: 15px;
    margin-bottom: 20px;
}

.header h1 {
    margin: 0;
    font-size: 25px;
}

.header h2 {
    margin: 7px 0 0;
    font-size: 18px;
    font-weight: normal;
}

.info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 30px;
    margin-bottom: 22px;
    font-size: 14px;
}

.info strong {
    display: inline-block;
    min-width: 115px;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

th,
td {
    border: 1px solid #9ca3af;
    padding: 8px 6px;
}

th {
    background: #e5e7eb;
    text-align: center;
}

td.center {
    text-align: center;
}

.good {
    font-weight: bold;
}

.warning {
    font-weight: bold;
}

.shortage {
    font-weight: bold;
}

.actions {
    max-width: 1100px;
    margin: 20px auto;
    text-align: right;
}

button {
    border: 0;
    background: #2563eb;
    color: white;
    padding: 10px 18px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
}

.empty {
    text-align: center;
    padding: 25px;
}

.footer {
    margin-top: 30px;
    font-size: 11px;
    text-align: right;
}

@media print {

    body {
        background: white;
    }

    .actions {
        display: none;
    }

    .report {
        margin: 0;
        max-width: none;
        padding: 10mm;
    }

    @page {
        size: A4 landscape;
        margin: 10mm;
    }
}

</style>

</head>

<body>


<div class="actions">

    <button onclick="window.print()">
        Print / Save as PDF
    </button>

</div>


<div class="report">


<div class="header">

    <h1>
        EduTrack ERP
    </h1>

    <h2>
        Attendance Report
    </h2>

</div>


<div class="info">

    <div>
        <strong>Teacher:</strong>
        <?= htmlspecialchars($teacher_name) ?>
    </div>

    <div>
        <strong>Department:</strong>
        <?= htmlspecialchars($class['department']) ?>
    </div>


    <div>
        <strong>Class:</strong>
        <?= htmlspecialchars($class['class_name']) ?>
    </div>

    <div>
        <strong>Semester:</strong>
        <?= (int)$class['semester'] ?>
    </div>


    <div>
        <strong>Subject:</strong>
        <?= htmlspecialchars(
            $class['subject_name']
            ?: $class['class_name']
        ) ?>
    </div>

    <div>
        <strong>Academic Year:</strong>
        <?= htmlspecialchars(
            $class['academic_year'] ?? '-'
        ) ?>
    </div>


    <div>
        <strong>From:</strong>
        <?= $from_date !== ''
            ? htmlspecialchars($from_date)
            : 'All Dates' ?>
    </div>

    <div>
        <strong>To:</strong>
        <?= $to_date !== ''
            ? htmlspecialchars($to_date)
            : 'All Dates' ?>
    </div>

</div>


<table>

<thead>

<tr>

    <th>#</th>
    <th>Roll No.</th>
    <th>Student Name</th>
    <th>Total</th>
    <th>Effective Present</th>
    <th>Absent</th>
    <th>Grace</th>
    <th>Attendance %</th>
    <th>Status</th>

</tr>

</thead>


<tbody>

<?php if (empty($report)): ?>

<tr>

<td
    colspan="9"
    class="empty"
>
    No attendance records found.
</td>

</tr>


<?php else: ?>


<?php
$sn = 1;

foreach ($report as $row):

    if ($row['percentage'] >= 85) {

        $status = 'Good';
        $status_class = 'good';

    } elseif ($row['percentage'] >= 75) {

        $status = 'Warning';
        $status_class = 'warning';

    } else {

        $status = 'Shortage';
        $status_class = 'shortage';
    }
?>

<tr>

<td class="center">
    <?= $sn++ ?>
</td>

<td>
    <?= htmlspecialchars(
        $row['roll_no']
    ) ?>
</td>

<td>
    <?= htmlspecialchars(
        $row['student_name']
    ) ?>
</td>

<td class="center">
    <?= $row['total'] ?>
</td>

<td class="center">
    <?= $row['present'] ?>
</td>

<td class="center">
    <?= $row['absent'] ?>
</td>

<td class="center">
    <?= $row['grace'] ?>
</td>

<td class="center">
    <?= number_format(
        $row['percentage'],
        1
    ) ?>%
</td>

<td
    class="center <?= $status_class ?>"
>
    <?= $status ?>
</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>


<div class="footer">

Generated:
<?= date('d M Y, h:i A') ?>

</div>


</div>

</body>

</html>