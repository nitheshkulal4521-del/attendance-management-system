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
 * Your login stores users.user_id in $_SESSION['teacher_id'].
 */
$user_id = (int)$_SESSION['teacher_id'];


/* =========================================================
   GET ACTUAL TEACHER
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        teacher_id,
        teacher_name,
        department
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

$teacher_id   = (int)$teacher['teacher_id'];
$teacher_name = $teacher['teacher_name'];


/* =========================================================
   MESSAGE
   ========================================================= */

$message = "";
$error   = "";


/* =========================================================
   GRANT / DENY GRACE
   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $attendance_id = isset($_POST['attendance_id'])
        ? (int)$_POST['attendance_id']
        : 0;

    $action = $_POST['action'] ?? '';

    $remarks = trim(
        $_POST['remarks'] ?? ''
    );

    /*
     * Verify:
     * 1. Attendance exists.
     * 2. Attendance is Absent.
     * 3. Session belongs to this teacher's class.
     */
    $verify = $conn->prepare("
        SELECT
            a.attendance_id,
            a.student_id,
            a.status,
            sess.class_id

        FROM attendance a

        INNER JOIN class_sessions sess
            ON sess.session_id = a.session_id

        INNER JOIN classes c
            ON c.class_id = sess.class_id

        INNER JOIN class_students cst
            ON cst.class_id = c.class_id
           AND cst.student_id = a.student_id

        WHERE a.attendance_id = ?
          AND a.status = 'Absent'
          AND c.teacher_id = ?

        LIMIT 1
    ");

    $verify->bind_param(
        "ii",
        $attendance_id,
        $teacher_id
    );

    $verify->execute();

    $valid_attendance =
        $verify->get_result()->fetch_assoc();

    $verify->close();


    if (!$valid_attendance) {

        $error =
            "Invalid attendance record or you do not have permission to modify it.";

    } elseif (
        $action !== 'grant' &&
        $action !== 'deny'
    ) {

        $error = "Invalid grace action.";

    } else {

        $grace_status =
            $action === 'grant'
                ? 'Granted'
                : 'Denied';


        /*
         * attendance_id is UNIQUE in grace_attendance.
         *
         * If the teacher changes a previous decision,
         * update that same grace record.
         */
        $save = $conn->prepare("
            INSERT INTO grace_attendance
            (
                attendance_id,
                teacher_id,
                status,
                remarks
            )
            VALUES (?, ?, ?, ?)

            ON DUPLICATE KEY UPDATE
                teacher_id = VALUES(teacher_id),
                status = VALUES(status),
                remarks = VALUES(remarks),
                action_at = CURRENT_TIMESTAMP
        ");

        $save->bind_param(
            "iiss",
            $attendance_id,
            $teacher_id,
            $grace_status,
            $remarks
        );


        if ($save->execute()) {

            /*
             * Keep absence_reasons separate from grace.
             *
             * If teacher grants grace, an existing pending
             * reason becomes Approved.
             *
             * If teacher denies grace, an existing pending
             * reason becomes Rejected.
             *
             * We do NOT create a fake reason when none exists.
             */
            $reason_status =
                $action === 'grant'
                    ? 'Approved'
                    : 'Rejected';

            $reason_update = $conn->prepare("
                UPDATE absence_reasons ar

                INNER JOIN attendance a
                    ON a.student_id = ar.student_id
                   AND a.session_id = ar.session_id

                SET ar.status = ?

                WHERE a.attendance_id = ?
                  AND ar.status = 'Pending'
            ");

            $reason_update->bind_param(
                "si",
                $reason_status,
                $attendance_id
            );

            $reason_update->execute();
            $reason_update->close();


            $message =
                $action === 'grant'
                    ? "Grace attendance granted successfully."
                    : "Grace attendance denied successfully.";

        } else {

            $error =
                "Unable to save the grace decision.";
        }

        $save->close();
    }
}


/* =========================================================
   FILTERS
   ========================================================= */

/*
 * When opened from Shortage List:
 *
 * grace_management.php?student_id=20&class_id=16
 *
 * class_id is automatically selected.
 */

$selected_student = isset($_GET['student_id'])
    ? (int)$_GET['student_id']
    : 0;

$selected_class = isset($_GET['class_id'])
    ? (int)$_GET['class_id']
    : 0;

$selected_semester = isset($_GET['semester'])
    ? (int)$_GET['semester']
    : 0;

$selected_date = trim(
    $_GET['date'] ?? ''
);

$selected_status =
    $_GET['grace_status'] ?? 'All';


$allowed_statuses = [
    'All',
    'Pending',
    'Granted',
    'Denied'
];

if (
    !in_array(
        $selected_status,
        $allowed_statuses,
        true
    )
) {
    $selected_status = 'All';
}


/* Validate date */

if (
    $selected_date !== '' &&
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $selected_date
    )
) {
    $selected_date = '';
}


/* =========================================================
   GET TEACHER CLASSES
   ========================================================= */

$class_stmt = $conn->prepare("
    SELECT
        c.class_id,
        c.class_name,
        c.department,
        c.semester,
        c.academic_year,
        c.room_number
    FROM classes c
    WHERE c.teacher_id = ?
    ORDER BY
        c.semester ASC,
        c.class_name ASC
");

$class_stmt->bind_param(
    "i",
    $teacher_id
);

$class_stmt->execute();

$class_result =
    $class_stmt->get_result();

$teacher_classes = [];
$semesters = [];

while (
    $row =
        $class_result->fetch_assoc()
) {

    $teacher_classes[] = $row;

    $sem =
        (int)$row['semester'];

    if (
        !in_array(
            $sem,
            $semesters,
            true
        )
    ) {
        $semesters[] = $sem;
    }
}

$class_stmt->close();

sort($semesters);


/* =========================================================
   VALIDATE CLASS
   ========================================================= */

if ($selected_class > 0) {

    $valid_class = false;

    foreach (
        $teacher_classes as $class
    ) {

        if (
            (int)$class['class_id'] ===
            $selected_class
        ) {

            if (
                $selected_semester === 0 ||
                (int)$class['semester'] ===
                $selected_semester
            ) {

                $valid_class = true;
            }

            break;
        }
    }

    if (!$valid_class) {
        $selected_class = 0;
    }
}


/* =========================================================
   GET ABSENT ATTENDANCE RECORDS
   ========================================================= */

/*
 * Each row represents one actual absence.
 *
 * Grace is therefore tied to:
 *
 * student + session + subject/class
 */

$sql = "
    SELECT
        a.attendance_id,
        a.student_id,
        a.session_id,

        st.roll_no,
        st.student_name,

        c.class_id,
        c.class_name,
        c.department,
        c.semester,

        sess.class_date,

        ar.reason_id,
        ar.reason,
        ar.proof_file,
        ar.status AS reason_status,

        ga.grace_id,
        ga.status AS grace_status,
        ga.remarks AS grace_remarks,
        ga.action_at,

        (
            
    SELECT COUNT(DISTINCT s2.session_id)

    FROM class_sessions s2

    WHERE s2.class_id = c.class_id

) AS total_classes,

(
    SELECT COUNT(DISTINCT a2.session_id)

    FROM attendance a2

    INNER JOIN class_sessions s3
        ON s3.session_id = a2.session_id

    LEFT JOIN grace_attendance g2
        ON g2.attendance_id = a2.attendance_id
       AND g2.status = 'Granted'

    WHERE a2.student_id = a.student_id

      AND s3.class_id = c.class_id

      AND (
            a2.status = 'Present'
            OR g2.status = 'Granted'
          )

) AS effective_present

    FROM attendance a

    INNER JOIN students st
        ON st.student_id =
           a.student_id

    INNER JOIN class_sessions sess
        ON sess.session_id =
           a.session_id

    INNER JOIN classes c
        ON c.class_id =
           sess.class_id

    INNER JOIN class_students cst
        ON cst.class_id =
           c.class_id
       AND cst.student_id =
           st.student_id

    LEFT JOIN absence_reasons ar
        ON ar.student_id =
           a.student_id
       AND ar.session_id =
           a.session_id

    LEFT JOIN grace_attendance ga
        ON ga.attendance_id =
           a.attendance_id

    WHERE c.teacher_id = ?

      AND a.status = 'Absent'
";

$types  = "i";
$params = [$teacher_id];


/* Student passed from shortage page */

if ($selected_student > 0) {

    $sql .= "
        AND st.student_id = ?
    ";

    $types .= "i";
    $params[] =
        $selected_student;
}


/* Semester */

if ($selected_semester > 0) {

    $sql .= "
        AND c.semester = ?
    ";

    $types .= "i";
    $params[] =
        $selected_semester;
}


/* Class */

if ($selected_class > 0) {

    $sql .= "
        AND c.class_id = ?
    ";

    $types .= "i";
    $params[] =
        $selected_class;
}


/* Date */

if ($selected_date !== '') {

    $sql .= "
        AND sess.class_date = ?
    ";

    $types .= "s";
    $params[] =
        $selected_date;
}


/* Grace Status */

if ($selected_status === 'Pending') {

    $sql .= "
        AND ga.grace_id IS NULL
    ";

} elseif (
    $selected_status === 'Granted'
) {

    $sql .= "
        AND ga.status = 'Granted'
    ";

} elseif (
    $selected_status === 'Denied'
) {

    $sql .= "
        AND ga.status = 'Denied'
    ";
}


$sql .= "
    ORDER BY
        sess.class_date DESC,
        c.class_name ASC,
        st.roll_no ASC
";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$result =
    $stmt->get_result();

$records = [];

while (
    $row =
        $result->fetch_assoc()
) {

    $total =
        (int)$row['total_classes'];

    $present =
        (int)$row['effective_present'];

    $percentage =
        $total > 0
            ? round(
                ($present / $total) * 100,
                1
            )
            : 0;

    $row['current_percentage'] =
        $percentage;

    /*
     * No grace record means Pending.
     */
    $row['display_grace_status'] =
        $row['grace_status']
            ?: 'Pending';

    $records[] = $row;
}

$stmt->close();


/* =========================================================
   STATS
   ========================================================= */

$total_absent = count($records);

$pending_count = 0;
$granted_count = 0;
$denied_count  = 0;

foreach ($records as $record) {

    switch (
        $record[
            'display_grace_status'
        ]
    ) {

        case 'Granted':
            $granted_count++;
            break;

        case 'Denied':
            $denied_count++;
            break;

        default:
            $pending_count++;
            break;
    }
}


/* =========================================================
   HELPER
   ========================================================= */

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
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
    Grace Management – EduTrack ERP
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

.filter-panel{
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    padding:22px;
    margin-bottom:24px
}

.filter-title{
    font-size:0.9rem;
    font-weight:700;
    color:var(--slate);
    margin-bottom:16px;
    display:flex;
    align-items:center;
    gap:8px
}

.filter-title svg{
    width:16px;
    height:16px;
    stroke:var(--blue);
    fill:none;
    stroke-width:2
}

.filter-row{
    display:flex;
    gap:14px;
    flex-wrap:wrap;
    align-items:flex-end
}

.filter-col{
    display:flex;
    flex-direction:column;
    gap:6px;
    min-width:160px
}

.grace-stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:24px
}

.gstat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:18px;
    text-align:center
}

.gstat-val{
    font-family:'DM Serif Display',serif;
    font-size:1.6rem;
    color:var(--slate)
}

.gstat-key{
    font-size:0.72rem;
    color:var(--muted);
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.3px;
    margin-top:4px
}

.grace-reason{
    font-size:0.78rem;
    color:var(--muted);
    max-width:180px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis
}

.grace-actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap
}

.modal-overlay{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.4);
    backdrop-filter:blur(4px);
    z-index:100;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px
}

.modal-overlay.open{
    display:flex
}

.modal{
    background:#fff;
    border-radius:18px;
    padding:30px;
    width:100%;
    max-width:470px;
    box-shadow:0 20px 60px rgba(0,0,0,.15);
    animation:fadeUp .25s ease
}

@keyframes fadeUp{

    from{
        opacity:0;
        transform:translateY(16px)
    }

    to{
        opacity:1;
        transform:translateY(0)
    }

}

.modal-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px
}

.modal-title{
    font-family:'DM Serif Display',serif;
    font-size:1.2rem;
    color:var(--slate)
}

.modal-close{
    width:30px;
    height:30px;
    background:var(--bg);
    border:1px solid var(--border);
    border-radius:7px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer
}

.modal-close svg{
    width:13px;
    height:13px;
    stroke:var(--muted);
    fill:none;
    stroke-width:2
}

.info-row{
    display:flex;
    justify-content:space-between;
    gap:15px;
    padding:9px 0;
    border-bottom:1px solid var(--border);
    font-size:0.85rem
}

.info-label{
    color:var(--muted);
    font-weight:600
}

.info-val{
    color:var(--slate);
    font-weight:600;
    text-align:right
}

.modal-actions{
    display:flex;
    gap:10px;
    margin-top:20px
}

.modal-actions .btn{
    flex:1;
    justify-content:center
}

.empty-state{
    text-align:center;
    padding:45px 20px;
    color:var(--muted)
}

.reason-status{
    display:block;
    font-size:.7rem;
    margin-top:3px;
    font-weight:600
}
.topbar-right{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:14px;
}

.profile-btn{
    display:flex;
    align-items:center;
    gap:12px;
    width:140px;
    height:44px;
    padding:0 16px;
    border:1px solid #bfdbfe;
    border-radius:12px;
    background:#eff6ff;
    cursor:pointer;
    transition:.2s;
}

.profile-btn:hover{
    background:#dbeafe;
}

.profile-avatar{
    width:36px;
    height:36px;
    border-radius:50%;
    background:#2563eb;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size:15px;
    flex-shrink:0;
}

.nav-user{
    font-size:16px;
    font-weight:600;
    color:#1e293b;
    white-space:nowrap;
}

.btn-logout{
    width:100px;
    height:44px;
    display:flex;
    align-items:center;
    justify-content:center;
   border:1px solid #bfdbfe;  /* Soft gray border */
    border-radius:12px;
    background:#eff6ff;
    color:#475569;
    text-decoration:none;
    font-size:17px;               /* Increased from default */
    font-weight:700;              /* Bolder text */
    font-family:'DM Sans',sans-serif;
    box-sizing:border-box;
    transition:.2s ease;
}

.btn-logout:hover{
    background:#fef2f2;
    border-color:#ef4444;
    color:#dc2626;
}
@media(max-width:800px){

    .grace-stats{
        grid-template-columns:repeat(2,1fr)
    }

}

</style>

</head>

<body>


<div class="topbar">
    
    <div class="topbar-logo">
        🎓
    </div>

    <span class="topbar-brand">
        EduTrack
    </span>

    <span class="topbar-page">
        Grace Management
    </span>

    <div class="topbar-right">

        <div class="profile-btn" onclick="toggleProfile(event)">

            <div class="profile-avatar">
                <?= strtoupper(substr($teacher_name,0,1)) ?>
            </div>

            <span class="nav-user">
                <?= htmlspecialchars($teacher_name) ?>
            </span>

        </div>

        <a href="logout.php" class="btn-logout">
            Logout
        </a>

    </div>

</div>

<div
    id="sidebar-mount"
    data-active="grace"
></div>


<div class="main">

<div class="page-content">


<!-- =====================================================
     BREADCRUMB
     ===================================================== -->

<div class="breadcrumb">

<a href="teacher_dashboard.php">
    Dashboard
</a>

<svg viewBox="0 0 24 24">
    <polyline
        points="9 18 15 12 9 6"
    />
</svg>

<span>
    Grace Management
</span>

</div>


<!-- =====================================================
     HEADER
     ===================================================== -->

<div class="page-header">

<h1 class="page-title">
    Grace Management
</h1>

<p class="page-sub">
    Review and grant attendance grace to absent students
</p>

</div>


<!-- =====================================================
     MESSAGES
     ===================================================== -->

<?php if ($message !== '') { ?>

<div
    class="alert alert-success"
    style="margin-bottom:18px"
>

<?= e($message) ?>

</div>

<?php } ?>


<?php if ($error !== '') { ?>

<div
    class="alert alert-danger"
    style="margin-bottom:18px"
>

<?= e($error) ?>

</div>

<?php } ?>


<!-- =====================================================
     FILTER
     ===================================================== -->

<div class="filter-panel">


<div class="filter-title">

<svg viewBox="0 0 24 24">

<polygon
    points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"
/>

</svg>

Filter Absent Students

</div>


<form
    method="GET"
    class="filter-row"
>


<!-- Date -->

<div class="filter-col">

<label class="form-label">
    Select Date
</label>

<input
    type="date"
    name="date"
    class="form-control"
    value="<?= e($selected_date) ?>"
>

</div>


<!-- Semester -->

<div class="filter-col">

<label class="form-label">
    Semester
</label>

<select
    name="semester"
    id="semesterFilter"
    class="form-control"
    onchange="filterClassOptions()"
>

<option value="0">
    All Semesters
</option>

<?php foreach ($semesters as $semester) { ?>

<option
    value="<?= $semester ?>"
    <?= $selected_semester === $semester
        ? 'selected'
        : '' ?>
>

Semester <?= $semester ?>

</option>

<?php } ?>

</select>

</div>


<!-- Class -->

<div class="filter-col">

<label class="form-label">
    Class / Subject
</label>

<select
    name="class_id"
    id="classFilter"
    class="form-control"
>

<option value="0">
    All Classes
</option>

<?php foreach ($teacher_classes as $class) { ?>

<option
    value="<?= (int)$class['class_id'] ?>"
    data-semester="<?= (int)$class['semester'] ?>"
    <?= $selected_class ===
        (int)$class['class_id']
            ? 'selected'
            : '' ?>
>

<?= e($class['class_name']) ?>

</option>

<?php } ?>

</select>

</div>


<!-- Status -->

<div class="filter-col">

<label class="form-label">
    Grace Status
</label>

<select
    name="grace_status"
    class="form-control"
>

<?php foreach (
    $allowed_statuses as $status
) { ?>

<option
    value="<?= e($status) ?>"
    <?= $selected_status === $status
        ? 'selected'
        : '' ?>
>

<?= e($status) ?>

</option>

<?php } ?>

</select>

</div>


<!-- Preserve selected student -->

<?php if ($selected_student > 0) { ?>

<input
    type="hidden"
    name="student_id"
    value="<?= $selected_student ?>"
>

<?php } ?>


<button
    type="submit"
    class="btn btn-primary"
>

<svg viewBox="0 0 24 24">

<circle
    cx="11"
    cy="11"
    r="8"
/>

<line
    x1="21"
    y1="21"
    x2="16.65"
    y2="16.65"
/>

</svg>

Fetch Records

</button>


<a
    href="grace_management.php"
    class="btn btn-ghost"
>

Clear

</a>


</form>

</div>


<!-- =====================================================
     STATS
     ===================================================== -->

<div class="grace-stats">


<div class="gstat">

<div class="gstat-val">
    <?= $total_absent ?>
</div>

<div class="gstat-key">
    Total Absent
</div>

</div>


<div class="gstat">

<div
    class="gstat-val"
    style="color:var(--yellow)"
>

<?= $pending_count ?>

</div>

<div class="gstat-key">
    Pending Grace
</div>

</div>


<div class="gstat">

<div
    class="gstat-val"
    style="color:var(--green)"
>

<?= $granted_count ?>

</div>

<div class="gstat-key">
    Grace Granted
</div>

</div>


<div class="gstat">

<div
    class="gstat-val"
    style="color:var(--red)"
>

<?= $denied_count ?>

</div>

<div class="gstat-key">
    Grace Denied
</div>

</div>


</div>


<!-- =====================================================
     INFO
     ===================================================== -->

<div
    class="alert alert-warning"
    style="margin-bottom:16px"
>

<svg viewBox="0 0 24 24">

<path
    d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"
/>

<line
    x1="12"
    y1="9"
    x2="12"
    y2="13"
/>

<line
    x1="12"
    y1="17"
    x2="12.01"
    y2="17"
/>

</svg>

<span>

Grace does not erase the original absence.
A granted grace record is treated as effective attendance
when attendance percentage is calculated.

</span>

</div>


<!-- =====================================================
     TABLE
     ===================================================== -->

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
<th>Student</th>
<th>Roll No.</th>
<th>Class</th>
<th>Date</th>
<th>Current %</th>
<th>Reason Submitted</th>
<th>Grace Status</th>
<th>Actions</th>

</tr>

</thead>


<tbody>


<?php if (count($records) > 0) { ?>


<?php

$sn = 1;

foreach ($records as $record) {

    $grace_status =
        $record[
            'display_grace_status'
        ];

    $reason =
        trim(
            (string)$record['reason']
        );

    if ($reason === '') {
        $reason =
            'No reason submitted';
    }

    $reason_status =
        $record['reason_status']
            ?: '';

    $date_display =
        date(
            'd M Y',
            strtotime(
                $record['class_date']
            )
        );

    $pct =
        (float)$record[
            'current_percentage'
        ];

    if ($pct < 75) {

        $pct_color =
            'var(--red)';

    } elseif ($pct < 85) {

        $pct_color =
            'var(--yellow)';

    } else {

        $pct_color =
            'var(--green)';
    }

?>


<tr>


<td>
    <?= $sn++ ?>
</td>


<td style="font-weight:700">

<?= e(
    $record['student_name']
) ?>

</td>


<td
    style="
        font-family:monospace;
        font-size:.82rem;
        color:var(--blue);
        font-weight:600
    "
>

<?= e(
    $record['roll_no']
) ?>

</td>


<td>

<?= e(
    $record['class_name']
) ?>

</td>


<td>

<?= e($date_display) ?>

</td>


<td>

<span
    style="
        color:<?= $pct_color ?>;
        font-weight:700
    "
>

<?= number_format(
    $pct,
    1
) ?>%

</span>

</td>


<td>

<div
    class="grace-reason"
    title="<?= e($reason) ?>"
>

<?= e($reason) ?>

</div>


<?php if (
    $reason_status !== ''
) { ?>

<span class="reason-status">

Reason:
<?= e($reason_status) ?>

</span>

<?php } ?>


</td>


<td>


<?php if (
    $grace_status === 'Granted'
) { ?>

<span class="badge badge-green">

<span class="badge-dot"></span>

Granted

</span>


<?php } elseif (
    $grace_status === 'Denied'
) { ?>

<span class="badge badge-red">

<span class="badge-dot"></span>

Denied

</span>


<?php } else { ?>

<span class="badge badge-yellow">

<span class="badge-dot"></span>

Pending

</span>

<?php } ?>


</td>


<td>


<div class="grace-actions">


<?php if (
    $grace_status === 'Pending'
) { ?>


<button
    type="button"
    class="btn btn-success btn-xs"

    onclick='openGraceModal(
        <?= json_encode(
            (int)$record[
                "attendance_id"
            ]
        ) ?>,

        <?= json_encode(
            $record[
                "student_name"
            ]
        ) ?>,

        <?= json_encode(
            $record[
                "roll_no"
            ]
        ) ?>,

        <?= json_encode(
            $record[
                "class_name"
            ]
        ) ?>,

        <?= json_encode(
            $date_display
        ) ?>,

        <?= json_encode(
            number_format(
                $pct,
                1
            ) . "%"
        ) ?>,

        <?= json_encode(
            $reason
        ) ?>
    )'
>

Grant

</button>


<button
    type="button"
    class="btn btn-danger btn-xs"
    onclick="openDenyModal(<?= (int)$record['attendance_id'] ?>)"
>
    Deny
</button>



<?php } elseif (
    $grace_status === 'Granted'
) { ?>


<button
    type="button"
    class="btn btn-ghost btn-xs"
    disabled
>

Granted

</button>


<?php } else { ?>


<button
    type="button"
    class="btn btn-ghost btn-xs"
    disabled
>

Denied

</button>


<?php } ?>


<button
    type="button"
    class="btn btn-ghost btn-xs"

    onclick='viewReason(
        <?= json_encode(
            $record[
                "student_name"
            ]
        ) ?>,

        <?= json_encode(
            $record[
                "roll_no"
            ]
        ) ?>,

        <?= json_encode(
            $reason
        ) ?>,

        <?= json_encode(
            $reason_status
                ?: "Not submitted"
        ) ?>,

        <?= json_encode(
            $record[
                "proof_file"
            ] ?: ""
        ) ?>,

        <?= json_encode(
            $grace_status
        ) ?>,

        <?= json_encode(
            $record[
                "grace_remarks"
            ] ?: ""
        ) ?>
    )'
>

View

</button>


</div>


</td>


</tr>


<?php } ?>


<?php } else { ?>


<tr>

<td
    colspan="9"
    class="empty-state"
>

No absent attendance records found
for the selected filters.

</td>

</tr>


<?php } ?>


</tbody>

</table>

</div>

</div>


</div>

</div>


<!-- =====================================================
     GRANT MODAL
     ===================================================== -->

<div
    class="modal-overlay"
    id="graceModal"
>

<div class="modal">


<div class="modal-header">

<h2 class="modal-title">
    Grant Grace Attendance
</h2>

<div
    class="modal-close"
    onclick="closeGraceModal()"
>

<svg viewBox="0 0 24 24">

<line
    x1="18"
    y1="6"
    x2="6"
    y2="18"
/>

<line
    x1="6"
    y1="6"
    x2="18"
    y2="18"
/>

</svg>

</div>

</div>


<div
    class="alert alert-info"
    style="margin-bottom:16px"
>

Grace will count this absence as
effective attendance while preserving
the original Absent record.

</div>


<div id="modalDetails"></div>


<form
    method="POST"
    id="grantForm"
>


<input
    type="hidden"
    name="attendance_id"
    id="modalAttendanceId"
>


<input
    type="hidden"
    name="action"
    value="grant"
>


<div
    class="form-group"
    style="margin-top:14px"
>

<label class="form-label">
    Grace Remarks
</label>

<textarea
    class="form-control"
    name="remarks"
    rows="3"
    placeholder="Optional: Add a note about why grace is being granted..."
></textarea>

</div>


<div class="modal-actions">


<button
    type="button"
    class="btn btn-ghost"
    onclick="closeGraceModal()"
>

Cancel

</button>


<button
    type="submit"
    class="btn btn-success"
>

<svg viewBox="0 0 24 24">

<path
    d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"
/>

</svg>

Confirm & Grant

</button>


</div>


</form>


</div>

</div>


<!-- =====================================================
     VIEW MODAL
     ===================================================== -->

<div
    class="modal-overlay"
    id="viewModal"
>

<div class="modal">


<div class="modal-header">

<h2 class="modal-title">
    Absence Details
</h2>

<div
    class="modal-close"
    onclick="closeViewModal()"
>

<svg viewBox="0 0 24 24">

<line
    x1="18"
    y1="6"
    x2="6"
    y2="18"
/>

<line
    x1="6"
    y1="6"
    x2="18"
    y2="18"
/>

</svg>

</div>

</div>


<div id="viewDetails"></div>


<div
    style="
        margin-top:20px;
        display:flex;
        justify-content:flex-end
    "
>

<button
    type="button"
    class="btn btn-outline"
    onclick="closeViewModal()"
>

Close

</button>

</div>


</div>

</div>


<script src="shared.js"></script>

<script>

/* =========================================================
   CLASS FILTER
   ========================================================= */

function filterClassOptions() {

    const semester =
        document.getElementById(
            'semesterFilter'
        ).value;

    const classSelect =
        document.getElementById(
            'classFilter'
        );

    const options =
        classSelect.querySelectorAll(
            'option[data-semester]'
        );

    options.forEach(option => {

        const visible =
            semester === '0' ||
            option.dataset.semester ===
            semester;

        option.hidden = !visible;

        if (
            !visible &&
            option.selected
        ) {
            classSelect.value = '0';
        }

    });

}


/* =========================================================
   ESCAPE HTML
   ========================================================= */

function escapeHtml(value) {

    const div =
        document.createElement('div');

    div.textContent =
        value == null
            ? ''
            : String(value);

    return div.innerHTML;
}


/* =========================================================
   GRANT MODAL
   ========================================================= */

function openGraceModal(
    attendanceId,
    name,
    roll,
    cls,
    date,
    pct,
    reason
) {

    document.getElementById(
        'modalAttendanceId'
    ).value = attendanceId;


    document.getElementById(
        'modalDetails'
    ).innerHTML = `

        <div class="info-row">

            <span class="info-label">
                Student
            </span>

            <span class="info-val">
                ${escapeHtml(name)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Roll No.
            </span>

            <span class="info-val">
                ${escapeHtml(roll)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Class
            </span>

            <span class="info-val">
                ${escapeHtml(cls)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Absent Date
            </span>

            <span class="info-val">
                ${escapeHtml(date)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Current Attendance
            </span>

            <span
                class="info-val"
                style="color:var(--red)"
            >

                ${escapeHtml(pct)}

            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Reason
            </span>

            <span class="info-val">
                ${escapeHtml(reason)}
            </span>

        </div>
    `;


    document.getElementById(
        'graceModal'
    ).classList.add('open');
}


function closeGraceModal() {

    document.getElementById(
        'graceModal'
    ).classList.remove('open');

}


/* =========================================================
   VIEW REASON
   ========================================================= */

function viewReason(
    name,
    roll,
    reason,
    reasonStatus,
    proofFile,
    graceStatus,
    remarks
) {

    let proofHtml =
        '<span class="info-val">No proof submitted</span>';


    if (proofFile) {

        /*
         * Change uploads/ below if your proof files
         * are stored in another directory.
         */
        const proofUrl =
            'uploads/' +
            encodeURIComponent(
                proofFile
            );

        proofHtml = `

            <span class="info-val">

                <a
                    href="${proofUrl}"
                    target="_blank"
                    rel="noopener"
                >
                    View Proof
                </a>

            </span>
        `;
    }


    document.getElementById(
        'viewDetails'
    ).innerHTML = `

        <div class="info-row">

            <span class="info-label">
                Student
            </span>

            <span class="info-val">
                ${escapeHtml(name)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Roll No.
            </span>

            <span class="info-val">
                ${escapeHtml(roll)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Reason
            </span>

            <span class="info-val">
                ${escapeHtml(reason)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Reason Status
            </span>

            <span class="info-val">
                ${escapeHtml(reasonStatus)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Proof
            </span>

            ${proofHtml}

        </div>


        <div class="info-row">

            <span class="info-label">
                Grace Status
            </span>

            <span class="info-val">
                ${escapeHtml(graceStatus)}
            </span>

        </div>


        <div class="info-row">

            <span class="info-label">
                Grace Remarks
            </span>

            <span class="info-val">
                ${
                    escapeHtml(
                        remarks ||
                        'No remarks'
                    )
                }
            </span>

        </div>
    `;


    document.getElementById(
        'viewModal'
    ).classList.add('open');
}


function closeViewModal() {

    document.getElementById(
        'viewModal'
    ).classList.remove('open');

}


/* =========================================================
   CLOSE MODALS ON BACKGROUND CLICK
   ========================================================= */

document.getElementById(
    'graceModal'
).addEventListener(
    'click',
    function(event) {

        if (event.target === this) {
            closeGraceModal();
        }

    }
);


document.getElementById(
    'viewModal'
).addEventListener(
    'click',
    function(event) {

        if (event.target === this) {
            closeViewModal();
        }

    }
);


/* Initial class filter */

filterClassOptions();
function openDenyModal(id){

    document.getElementById("denyAttendanceId").value=id;

    document
        .getElementById("denyModal")
        .classList
        .add("open");

}

function closeDenyModal(){

    document
        .getElementById("denyModal")
        .classList
        .remove("open");

}
</script>
<div class="modal-overlay" id="denyModal">

    <div class="modal">

        <div class="modal-header">
            <h2 class="modal-title">Deny Grace Attendance</h2>

            <div class="modal-close" onclick="closeDenyModal()">
                ✕
            </div>
        </div>

        <p style="margin:15px 0;color:#64748b;">
            Are you sure you want to deny grace attendance for this student?
        </p>

        <form method="POST">

            <input type="hidden"
                   name="attendance_id"
                   id="denyAttendanceId">

            <input type="hidden"
                   name="action"
                   value="deny">

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-ghost"
                    onclick="closeDenyModal()">
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-danger">
                    Confirm Deny
                </button>

            </div>

        </form>

    </div>

</div>
</body>

</html>