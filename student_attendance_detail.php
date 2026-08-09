<?php

session_start();
require 'db_connect.php';

/* =========================================================
   LOGIN CHECK
   ========================================================= */

if (!isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login.php");
    exit();
}

/*
 * Your login stores users.user_id in $_SESSION['teacher_id']
 */
$user_id = (int)$_SESSION['teacher_id'];


/* =========================================================
   GET TEACHER
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        teacher_id,
        teacher_name
    FROM teachers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    session_destroy();
    header("Location: teacher_login.php");
    exit();
}

$teacher_id = (int)$teacher['teacher_id'];
$teacher_name = $teacher['teacher_name'];


/* =========================================================
   GET PARAMETERS
   ========================================================= */

$student_id = (int)($_GET['student_id'] ?? 0);
$class_id   = (int)($_GET['class_id'] ?? 0);

$from_date = trim($_GET['from_date'] ?? '');
$to_date   = trim($_GET['to_date'] ?? '');

if ($student_id <= 0 || $class_id <= 0) {
    die("Invalid student or class.");
}


/* =========================================================
   VERIFY STUDENT + CLASS + TEACHER
   ========================================================= */
$stmt = $conn->prepare("
    SELECT
        st.student_id,
        st.roll_no,
        st.student_name,
        st.semester,

        c.class_id,
        c.class_name,
        c.department,
        c.academic_year,
        c.room_number,

        s.subject_name

    FROM class_students cst

    INNER JOIN students st
        ON st.student_id = cst.student_id

    INNER JOIN classes c
        ON c.class_id = cst.class_id

    LEFT JOIN class_subjects cs
        ON cs.class_id = c.class_id

    LEFT JOIN subjects s
        ON s.subject_id = cs.subject_id

    WHERE st.student_id = ?
      AND c.class_id = ?
      AND c.teacher_id = ?

    LIMIT 1
");

$stmt->bind_param(
    "iii",
    $student_id,
    $class_id,
    $teacher_id
);

$stmt->execute();

$student = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$student) {
    die("Student not found or you do not have access to this class.");
}


/* =========================================================
   DATE CONDITION
   ========================================================= */

$date_sql = "";

$types = "ii";
$params = [
    $student_id,
    $class_id
];

if ($from_date !== '') {

    $date_sql .= "
        AND sess.class_date >= ?
    ";

    $types .= "s";
    $params[] = $from_date;
}

if ($to_date !== '') {

    $date_sql .= "
        AND sess.class_date <= ?
    ";

    $types .= "s";
    $params[] = $to_date;
}


/* =========================================================
   ATTENDANCE HISTORY
   ========================================================= */

$sql = "
    SELECT
        sess.session_id,
        sess.class_date,

        sub.subject_name,

        a.attendance_id,
        a.status,

        ga.status AS grace_status,
        ga.remarks AS grace_remarks

    FROM class_sessions sess

    INNER JOIN subjects sub
        ON sub.subject_id = sess.subject_id

    LEFT JOIN attendance a
        ON a.session_id = sess.session_id
       AND a.student_id = ?

    LEFT JOIN grace_attendance ga
        ON ga.attendance_id = a.attendance_id
       AND ga.status = 'Granted'

    WHERE sess.class_id = ?

    $date_sql

    ORDER BY
        sess.class_date DESC,
        sess.session_id DESC
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$result = $stmt->get_result();

$history = [];

$total = 0;

$actual_present = 0;
$grace_count = 0;
$absent = 0;

while ($row = $result->fetch_assoc()) {

    $total++;

    if ($row['status'] === 'Present') {

        $actual_present++;

        $row['display_status'] = 'Present';

    } elseif (
        $row['status'] === 'Absent' &&
        $row['grace_status'] === 'Granted'
    ) {

        $grace_count++;

        $row['display_status'] = 'Grace';

    } elseif ($row['status'] === 'Absent') {

        $absent++;

        $row['display_status'] = 'Absent';

    } else {

        $row['display_status'] = 'Not Marked';
    }

    $history[] = $row;
}

/*
 * Effective present includes granted grace.
 */
$present =
    $actual_present +
    $grace_count;
$stmt->close();


/* =========================================================
   PERCENTAGE
   ========================================================= */

$percentage = 0;

if ($total > 0) {

    $percentage = round(
        ($present / $total) * 100,
        1
    );
}


/* =========================================================
   STATUS
   ========================================================= */

if ($percentage >= 85) {

    $attendance_status = "Good";
    $status_class = "good";

} elseif ($percentage >= 75) {

    $attendance_status = "Warning";
    $status_class = "warning";

} else {

    $attendance_status = "Shortage";
    $status_class = "shortage";
}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Student Attendance Detail – EduTrack ERP
</title>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="shared.css"
>

<style>

.student-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    padding:22px;
    margin-bottom:20px;
}

.student-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    flex-wrap:wrap;
}

.student-name{
    font-family:'DM Serif Display',serif;
    font-size:1.35rem;
    color:var(--slate);
    margin-bottom:5px;
}

.student-meta{
    font-size:.84rem;
    color:var(--muted);
    line-height:1.8;
}

.status-box{
    padding:9px 16px;
    border-radius:10px;
    font-size:.82rem;
    font-weight:700;
}

.status-box.good{
    color:var(--green);
    background:var(--green-bg);
    border:1px solid #bbf7d0;
}

.status-box.warning{
    color:var(--yellow);
    background:var(--yellow-bg);
    border:1px solid #fde68a;
}

.status-box.shortage{
    color:var(--red);
    background:var(--red-bg);
    border:1px solid #fecaca;
}


/* ==========================
   STATISTICS
   ========================== */

.detail-stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:14px;
    margin-bottom:24px;
}

.stat-box{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    text-align:center;
}

.stat-number{
    font-family:'DM Serif Display',serif;
    font-size:1.6rem;
    color:var(--slate);
}

.stat-label{
    margin-top:4px;
    color:var(--muted);
    font-size:.72rem;
    text-transform:uppercase;
    font-weight:700;
}


/* ==========================
   FILTER
   ========================== */

.history-toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:14px;
}

.filter-buttons{
    display:flex;
    gap:8px;
}

.history-filter{
    border:1px solid var(--border);
    background:#fff;
    padding:7px 13px;
    border-radius:8px;
    font-family:'DM Sans',sans-serif;
    font-size:.78rem;
    font-weight:600;
    color:var(--muted);
    cursor:pointer;
}

.history-filter:hover{
    border-color:var(--blue);
    color:var(--blue);
}

.history-filter.active{
    background:var(--blue);
    color:#fff;
    border-color:var(--blue);
}


/* ==========================
   TABLE
   ========================== */

.status-present{
    color:var(--green);
    font-weight:700;
}

.status-absent{
    color:var(--red);
    font-weight:700;
}
.status-grace{
    color:var(--blue);
    font-weight:700;
}

.grace-row{
    background:var(--blue-bg);
}
.absent-row{
    background:var(--red-bg);
}

.no-records{
    text-align:center;
    padding:35px;
    color:var(--muted);
}

@media(max-width:800px){

    .detail-stats{
        grid-template-columns:
            repeat(2,1fr);
    }
}

</style>

</head>

<body>


<div
    id="topbar-mount"
    data-page="Student Attendance Detail"
    data-teacher="<?= htmlspecialchars($teacher_name) ?>">
</div>

<div
    id="sidebar-mount"
    data-active="report">
</div>


<div class="main">

<div class="page-content">


<!-- ==========================
     BREADCRUMB
     ========================== -->

<div class="breadcrumb">

<a href="teacher_dashboard.php">
    Dashboard
</a>

<svg viewBox="0 0 24 24">
    <polyline points="9 18 15 12 9 6"/>
</svg>

<a href="attendance_report.php">
    Attendance Report
</a>

<svg viewBox="0 0 24 24">
    <polyline points="9 18 15 12 9 6"/>
</svg>

<span>
    Student Detail
</span>

</div>


<!-- ==========================
     HEADER
     ========================== -->

<div
    class="page-header"
    style="
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:15px;
        flex-wrap:wrap
    "
>

<div>

<h1 class="page-title">
    Attendance Detail
</h1>

<p class="page-sub">
    Date-wise attendance history
</p>

</div>


<button
    type="button"
    class="btn btn-outline btn-sm"
    onclick="history.back()"
>
    ← Back to Report
</button>

</div>


<!-- ==========================
     STUDENT
     ========================== -->

<div class="student-card">

<div class="student-header">


<div>

<div class="student-name">

<?= htmlspecialchars(
    $student['student_name']
) ?>

</div>


<div class="student-meta">

<strong>Roll No:</strong>

<?= htmlspecialchars(
    $student['roll_no'] ?? '-'
) ?>

&nbsp; · &nbsp;

<strong>Semester:</strong>

<?= (int)$student['semester'] ?>

<br>


<strong>Department:</strong>

<?= htmlspecialchars(
    $student['department']
) ?>

&nbsp; · &nbsp;

<strong>Subject:</strong>

<?= htmlspecialchars(
    $student['subject_name']
    ?? $student['class_name']
) ?>

<br>


<strong>Academic Year:</strong>

<?= htmlspecialchars(
    $student['academic_year']
) ?>

<?php if (!empty($student['room_number'])) { ?>

&nbsp; · &nbsp;

<strong>Room:</strong>

<?= htmlspecialchars(
    $student['room_number']
) ?>

<?php } ?>


<?php if (
    $from_date !== '' ||
    $to_date !== ''
) { ?>

<br>

<strong>Report Period:</strong>

<?= $from_date !== ''
    ? htmlspecialchars($from_date)
    : 'Beginning' ?>

to

<?= $to_date !== ''
    ? htmlspecialchars($to_date)
    : 'Latest' ?>

<?php } ?>

</div>

</div>


<div
    class="status-box <?= $status_class ?>"
>

<?= $attendance_status ?>

·

<?= number_format(
    $percentage,
    1
) ?>%

</div>


</div>

</div>


<!-- ==========================
     STATISTICS
     ========================== -->

<div class="detail-stats">

<div class="stat-box">
    <div class="stat-number">
        <?= $total ?>
    </div>

    <div class="stat-label">
        Total Classes
    </div>
</div>


<div class="stat-box">
    <div
        class="stat-number"
        style="color:var(--green)"
    >
        <?= $actual_present ?>
    </div>

    <div class="stat-label">
        Actual Present
    </div>
</div>


<div class="stat-box">
    <div
        class="stat-number"
        style="color:var(--blue)"
    >
        <?= $grace_count ?>
    </div>

    <div class="stat-label">
        Grace Granted
    </div>
</div>


<div class="stat-box">
    <div
        class="stat-number"
        style="color:var(--red)"
    >
        <?= $absent ?>
    </div>

    <div class="stat-label">
        Absent
    </div>
</div>


<div class="stat-box">
    <div class="stat-number">
        <?= number_format(
            $percentage,
            1
        ) ?>%
    </div>

    <div class="stat-label">
        Attendance
    </div>
</div>

</div>

<!-- ==========================
     HISTORY HEADER
     ========================== -->

<div class="history-toolbar">

<div>

<h2
    style="
        font-family:'DM Serif Display',serif;
        font-size:1.05rem;
        color:var(--slate);
        margin:0
    "
>
    Attendance History
</h2>

</div>


<div class="filter-buttons">

<button
    type="button"
    class="history-filter active"
    onclick="filterHistory('all',this)"
>
    All
</button>

<button
    type="button"
    class="history-filter"
    onclick="filterHistory('Present',this)"
>
    Present
</button>

<button
    type="button"
    class="history-filter"
    onclick="filterHistory('Absent',this)"
>
    Absent
</button>
<button
    type="button"
    class="history-filter"
    onclick="filterHistory('Grace',this)"
>
    Grace
</button>

</div>

</div>


<!-- ==========================
     HISTORY TABLE
     ========================== -->

<div
    class="card"
    style="padding:0"
>

<div
    class="table-wrap"
    style="
        border:none;
        border-radius:14px
    "
>

<table>

<thead>

<tr>

<th>#</th>

<th>Date</th>

<th>Subject</th>

<th>Status</th>

</tr>

</thead>


<tbody id="historyBody">


<?php if (empty($history)) { ?>

<tr>

<td
    colspan="4"
    class="no-records"
>
    No attendance records found.
</td>

</tr>

<?php } else { ?>


<?php
$sn = 1;

foreach ($history as $record) {

    $status =
    $record['display_status']
    ?? 'Not Marked';

$is_absent =
    $status === 'Absent';

$is_grace =
    $status === 'Grace';
?>


<tr
    class="history-row
        <?= $is_absent ? 'absent-row' : '' ?>
        <?= $is_grace ? 'grace-row' : '' ?>"
    data-status="<?= htmlspecialchars($status) ?>"
>
<td>

<?= $sn++ ?>

</td>


<td>

<?=
    date(
        'd M Y',
        strtotime(
            $record['class_date']
        )
    )
?>

</td>


<td style="font-weight:600">

<?= htmlspecialchars(
    $record['subject_name']
) ?>

</td>


<td>

<?php if ($status === 'Present') { ?>

<span class="status-present">
    ✓ Present
</span>


<?php } elseif ($status === 'Grace') { ?>

<span
    class="status-grace"
    title="<?= htmlspecialchars(
        $record['grace_remarks']
        ?? 'Grace attendance granted'
    ) ?>"
>
    ✓ Grace Granted
</span>


<?php } elseif ($status === 'Absent') { ?>

<span class="status-absent">
    ✗ Absent
</span>


<?php } else { ?>

<span style="color:var(--muted)">
    Not Marked
</span>

<?php } ?>

</td>


</tr>


<?php } ?>


<?php } ?>


</tbody>

</table>

</div>

</div>


</div>

</div>


<script src="shared.js"></script>

<script>

/* =========================================================
   HISTORY FILTER
   ========================================================= */

function filterHistory(
    status,
    button
) {

    document
        .querySelectorAll(
            '.history-filter'
        )
        .forEach(btn => {

            btn.classList.remove(
                'active'
            );

        });


    button.classList.add(
        'active'
    );


    const rows =
        document.querySelectorAll(
            '.history-row'
        );


    rows.forEach(row => {

        const rowStatus =
            row.dataset.status;


        if (
            status === 'all' ||
            rowStatus === status
        ) {

            row.style.display = '';

        } else {

            row.style.display =
                'none';
        }

    });

}

</script>


</body>
</html>