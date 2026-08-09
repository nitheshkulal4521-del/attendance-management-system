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
 * IMPORTANT:
 * teacher_login.php stores users.user_id in:
 * $_SESSION['teacher_id']
 */
$user_id = (int) $_SESSION['teacher_id'];


/* =========================================================
   GET ACTUAL TEACHER
   ========================================================= */

function getActualTeacher($conn, $user_id)
{
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

    return $teacher;
}


/* =========================================================
   AJAX : GET STUDENTS
   ========================================================= */

if (
    isset($_GET['action']) &&
    $_GET['action'] === 'get_students'
) {

    header('Content-Type: application/json');

    $teacher = getActualTeacher($conn, $user_id);

    if (!$teacher) {

        echo json_encode([
            'success' => false,
            'message' => 'Teacher account not found.'
        ]);

        exit();
    }

    $teacher_id = (int) $teacher['teacher_id'];

    $class_id = (int) ($_GET['class_id'] ?? 0);

    if ($class_id <= 0) {

        echo json_encode([
            'success' => false,
            'message' => 'Invalid class.'
        ]);

        exit();
    }


    /* Verify class belongs to logged-in teacher */

    $check = $conn->prepare("
        SELECT class_id
        FROM classes
        WHERE class_id = ?
          AND teacher_id = ?
        LIMIT 1
    ");

    $check->bind_param(
        "ii",
        $class_id,
        $teacher_id
    );

    $check->execute();

    $class = $check
        ->get_result()
        ->fetch_assoc();

    $check->close();


    if (!$class) {

        echo json_encode([
            'success' => false,
            'message' => 'Class not found.'
        ]);

        exit();
    }


   /* Get students enrolled in this class */

$stmt = $conn->prepare("
    SELECT
        s.student_id,
        s.roll_no,
        s.student_name
    FROM class_students cs
    INNER JOIN students s
        ON s.student_id = cs.student_id
    WHERE cs.class_id = ?
    ORDER BY s.roll_no ASC, s.student_name ASC
");

    $stmt->bind_param(
        "i",
        $class_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $students = [];

    while ($row = $result->fetch_assoc()) {

        $students[] = [
            'id'   => (int) $row['student_id'],
            'roll' => $row['roll_no'],
            'name' => $row['student_name']
        ];
    }

    $stmt->close();


    echo json_encode([
        'success' => true,
        'students' => $students
    ]);

    exit();
}


/* =========================================================
   AJAX : SAVE ATTENDANCE
   ========================================================= */

if (
    isset($_GET['action']) &&
    $_GET['action'] === 'save_attendance'
) {

    header('Content-Type: application/json');


    /* ---------- Teacher ---------- */

    $teacher = getActualTeacher($conn, $user_id);

    if (!$teacher) {

        echo json_encode([
            'success' => false,
            'message' => 'Teacher account not found.'
        ]);

        exit();
    }

    $teacher_id = (int) $teacher['teacher_id'];


    /* ---------- JSON ---------- */

    $data = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {

        echo json_encode([
            'success' => false,
            'message' => 'Invalid request.'
        ]);

        exit();
    }


    $class_id =
        (int) ($data['class_id'] ?? 0);

    $class_date =
        trim($data['date'] ?? '');

    $attendance_data =
        $data['attendance'] ?? [];


    if (
        $class_id <= 0 ||
        $class_date === '' ||
        !is_array($attendance_data) ||
        empty($attendance_data)
    ) {

        echo json_encode([
            'success' => false,
            'message' => 'Attendance information is incomplete.'
        ]);

        exit();
    }


    /* ---------- Validate date ---------- */

    $date_check =
        DateTime::createFromFormat(
            'Y-m-d',
            $class_date
        );

    if (
        !$date_check ||
        $date_check->format('Y-m-d') !== $class_date
    ) {

        echo json_encode([
            'success' => false,
            'message' => 'Invalid attendance date.'
        ]);

        exit();
    }


    /* ---------- Verify class ---------- */

    $class_stmt = $conn->prepare("
        SELECT
            class_id,
            class_name
        FROM classes
        WHERE class_id = ?
          AND teacher_id = ?
        LIMIT 1
    ");

    $class_stmt->bind_param(
        "ii",
        $class_id,
        $teacher_id
    );

    $class_stmt->execute();

    $class =
        $class_stmt
            ->get_result()
            ->fetch_assoc();

    $class_stmt->close();


    if (!$class) {

        echo json_encode([
            'success' => false,
            'message' => 'Selected class is invalid.'
        ]);

        exit();
    }


    /* ---------- Get subject automatically ---------- */

    $subject_stmt = $conn->prepare("
        SELECT
            cs.subject_id,
            s.subject_name
        FROM class_subjects cs
        INNER JOIN subjects s
            ON s.subject_id = cs.subject_id
        WHERE cs.class_id = ?
        LIMIT 1
    ");

    $subject_stmt->bind_param(
        "i",
        $class_id
    );

    $subject_stmt->execute();

    $subject =
        $subject_stmt
            ->get_result()
            ->fetch_assoc();

    $subject_stmt->close();


    if (!$subject) {

        echo json_encode([
            'success' => false,
            'message' =>
                'No subject is linked to this class. Check Manage Classes.'
        ]);

        exit();
    }


    $subject_id =
        (int) $subject['subject_id'];


    /* =====================================================
       TRANSACTION
       ===================================================== */

    $conn->begin_transaction();

    try {


        /* -------------------------------------------------
           FIND SESSION FOR CLASS + SUBJECT + DATE
           ------------------------------------------------- */

        $session_stmt = $conn->prepare("
            SELECT session_id
            FROM class_sessions
            WHERE class_id = ?
              AND subject_id = ?
              AND class_date = ?
            LIMIT 1
        ");

        $session_stmt->bind_param(
            "iis",
            $class_id,
            $subject_id,
            $class_date
        );

        $session_stmt->execute();

        $session =
            $session_stmt
                ->get_result()
                ->fetch_assoc();

        $session_stmt->close();


        /* -------------------------------------------------
           CREATE SESSION IF NOT EXISTS
           ------------------------------------------------- */

        if ($session) {

            $session_id =
                (int) $session['session_id'];

        } else {

            $create_session = $conn->prepare("
                INSERT INTO class_sessions
                (
                    class_id,
                    subject_id,
                    class_date
                )
                VALUES (?, ?, ?)
            ");

            $create_session->bind_param(
                "iis",
                $class_id,
                $subject_id,
                $class_date
            );

            $create_session->execute();

            $session_id =
                (int) $conn->insert_id;

            $create_session->close();
        }


        /* -------------------------------------------------
           PREPARE QUERIES ONCE
           ------------------------------------------------- */

        $student_check = $conn->prepare("
    SELECT student_id
    FROM class_students
    WHERE student_id = ?
      AND class_id = ?
    LIMIT 1
");


        $attendance_check = $conn->prepare("
            SELECT attendance_id
            FROM attendance
            WHERE student_id = ?
              AND session_id = ?
            LIMIT 1
        ");


        $insert_attendance = $conn->prepare("
            INSERT INTO attendance
            (
                student_id,
                session_id,
                status
            )
            VALUES (?, ?, ?)
        ");


        $update_attendance = $conn->prepare("
            UPDATE attendance
            SET status = ?
            WHERE attendance_id = ?
        ");


        /* -------------------------------------------------
           SAVE STUDENTS
           ------------------------------------------------- */

        foreach (
            $attendance_data
            as $student_id => $status
        ) {

            $student_id =
                (int) $student_id;


            /* Validate */

            if (
                $student_id <= 0 ||
                !in_array(
                    $status,
                    ['Present', 'Absent'],
                    true
                )
            ) {
                continue;
            }


            /* Student must belong to selected class */

            $student_check->bind_param(
                "ii",
                $student_id,
                $class_id
            );

            $student_check->execute();

            $valid_student =
                $student_check
                    ->get_result()
                    ->fetch_assoc();


            if (!$valid_student) {
                continue;
            }


            /* Check existing attendance */

            $attendance_check->bind_param(
                "ii",
                $student_id,
                $session_id
            );

            $attendance_check->execute();

            $existing =
                $attendance_check
                    ->get_result()
                    ->fetch_assoc();


            if ($existing) {

                /* UPDATE */

                $attendance_id =
                    (int) $existing['attendance_id'];

                $update_attendance->bind_param(
                    "si",
                    $status,
                    $attendance_id
                );

                $update_attendance->execute();

            } else {

                /* INSERT */

                $insert_attendance->bind_param(
                    "iis",
                    $student_id,
                    $session_id,
                    $status
                );

                $insert_attendance->execute();
            }
        }

$student_check->close();
$attendance_check->close();
$insert_attendance->close();
$update_attendance->close();


/* =====================================================
   ACTIVITY LOG
   ===================================================== */

/*
 * Count Present / Absent from the data that was saved.
 */

$present_count = 0;
$absent_count  = 0;

foreach ($attendance_data as $status) {

    if ($status === 'Present') {
        $present_count++;
    }

    if ($status === 'Absent') {
        $absent_count++;
    }
}


/*
 * Build readable activity description.
 */

$class_name =
    $class['class_name'];

$subject_name =
    $subject['subject_name'];


$activity_description =
    'Attendance marked for ' .
    $class_name .
    ' - ' .
    $subject_name .
    ' on ' .
    date(
        'd M Y',
        strtotime($class_date)
    ) .
    '. ' .
    $present_count .
    ' present, ' .
    $absent_count .
    ' absent.';


/*
 * Save activity.
 */

$activity_stmt = $conn->prepare("
    INSERT INTO activity_logs
    (
        teacher_id,
        class_id,
        action_type,
        description
    )
    VALUES (?, ?, ?, ?)
");


$action_type =
    'Attendance Marked';


$activity_stmt->bind_param(
    "iiss",
    $teacher_id,
    $class_id,
    $action_type,
    $activity_description
);


$activity_stmt->execute();

$activity_stmt->close();


/* =====================================================
   COMMIT EVERYTHING
   ===================================================== */

$conn->commit();


echo json_encode([
    'success' => true,
    'message' => 'Attendance saved successfully.',
    'session_id' => $session_id
]);


    } catch (Throwable $e) {

        $conn->rollback();


        echo json_encode([
            'success' => false,
            'message' =>
                'Unable to save attendance: ' .
                $e->getMessage()
        ]);
    }


    exit();
}


/* =========================================================
   NORMAL PAGE
   ========================================================= */

$teacher = getActualTeacher(
    $conn,
    $user_id
);

if (!$teacher) {

    session_destroy();

    header("Location: teacher_login.php");
    exit();
}

$teacher_id =
    (int) $teacher['teacher_id'];


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
        c.room_number,

        s.subject_id,
        s.subject_name,

        COUNT(DISTINCT cst.student_id) AS student_count

    FROM classes c

    LEFT JOIN class_subjects cs
        ON cs.class_id = c.class_id

    LEFT JOIN subjects s
        ON s.subject_id = cs.subject_id

    LEFT JOIN class_students cst
        ON cst.class_id = c.class_id

    WHERE c.teacher_id = ?

    GROUP BY
        c.class_id,
        c.class_name,
        c.department,
        c.semester,
        c.academic_year,
        c.room_number,
        s.subject_id,
        s.subject_name

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

$classes = [];

while ($row = $class_result->fetch_assoc()) {
    $classes[] = $row;
}

$class_stmt->close();
/* Teacher name */

$teacher_name =
    $teacher['teacher_name'] ?? 'Teacher';

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Mark Attendance – EduTrack ERP</title>

<link
    href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="shared.css"
>


<style>

/* =========================================================
   FLOW
   ========================================================= */

.flow-step {
    display:none;
}

.flow-step.active {
    display:block;
}


/* =========================================================
   SEMESTER GRID
   ========================================================= */

.sem-grid {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    max-width:700px;
}

.sem-btn {
    background:#fff;
    border:2px solid var(--border);
    border-radius:14px;
    padding:22px 14px;
    text-align:center;
    cursor:pointer;
    transition:.2s;
}

.sem-btn:hover {
    border-color:var(--blue);
    transform:translateY(-2px);
    box-shadow:0 6px 20px rgba(37,99,235,.12);
}

.sem-btn.active {
    background:var(--blue);
    border-color:var(--blue);
}

.sem-num {
    font-family:'DM Serif Display',serif;
    font-size:1.7rem;
    color:var(--slate);
}

.sem-label {
    font-size:.72rem;
    font-weight:700;
    color:var(--muted);
    text-transform:uppercase;
    margin-top:3px;
}

.sem-btn.active .sem-num,
.sem-btn.active .sem-label {
    color:#fff;
}


/* =========================================================
   CLASS LIST
   ========================================================= */

.class-list {
    display:grid;
    grid-template-columns:repeat(
        auto-fill,
        minmax(260px,1fr)
    );
    gap:14px;
}

.class-pill {
    background:#fff;
    border:1.5px solid var(--border);
    border-radius:12px;
    padding:18px 20px;
    cursor:pointer;
    transition:.2s;
}

.class-pill:hover {
    border-color:var(--blue);
    box-shadow:0 4px 16px rgba(37,99,235,.1);
    transform:translateY(-1px);
}

.class-pill-name {
    font-weight:700;
    color:var(--slate);
    margin-bottom:5px;
}

.class-pill-info {
    font-size:.78rem;
    color:var(--muted);
    line-height:1.6;
}

.subject-name {
    color:var(--blue);
    font-weight:600;
}


/* =========================================================
   ATTENDANCE HEADER
   ========================================================= */

.attend-header {
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:14px;
    margin-bottom:20px;
}

.attend-info {
    display:flex;
    align-items:center;
    gap:16px;
    flex-wrap:wrap;
}


/* =========================================================
   TABLE
   ========================================================= */

.attend-table-wrap {
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    overflow:hidden;
}

.attend-row {
    display:grid;
    grid-template-columns:40px 1fr auto;
    align-items:center;
    padding:13px 20px;
    border-bottom:1px solid var(--border);
    gap:12px;
    transition:.15s;
}

.attend-row:last-child {
    border-bottom:none;
}

.attend-row:hover {
    background:#fafafa;
}

.attend-row.absent-row {
    background:var(--red-bg);
}

.row-num {
    font-size:.8rem;
    color:var(--muted);
    font-weight:600;
    text-align:center;
}

.row-name {
    font-weight:600;
    font-size:.9rem;
    color:var(--slate);
}

.row-roll {
    font-size:.75rem;
    color:var(--muted);
}


/* =========================================================
   PRESENT / ABSENT
   ========================================================= */

.toggle-wrap {
    display:flex;
    background:var(--bg);
    border:1.5px solid var(--border);
    border-radius:8px;
    overflow:hidden;
}

.toggle-btn {
    padding:6px 16px;
    font-family:'DM Sans',sans-serif;
    font-size:.78rem;
    font-weight:700;
    cursor:pointer;
    border:none;
    background:transparent;
    transition:.15s;
}

.toggle-btn.present.active {
    background:var(--green);
    color:#fff;
}

.toggle-btn.absent.active {
    background:var(--red);
    color:#fff;
}

.toggle-btn:not(.active) {
    color:var(--muted);
}


/* =========================================================
   SUMMARY
   ========================================================= */

.attend-summary {
    display:flex;
    gap:14px;
    margin-bottom:16px;
    flex-wrap:wrap;
}

.sum-chip {
    padding:8px 16px;
    border-radius:10px;
    font-size:.82rem;
    font-weight:700;
}

.sum-present {
    background:var(--green-bg);
    color:var(--green);
    border:1px solid #bbf7d0;
}

.sum-absent {
    background:var(--red-bg);
    color:var(--red);
    border:1px solid #fecaca;
}

.sum-total {
    background:var(--blue-bg);
    color:var(--blue);
    border:1px solid #bfdbfe;
}


/* =========================================================
   SAVE BAR
   ========================================================= */

.save-bar {
    position:sticky;
    bottom:0;
    background:#fff;
    border-top:1px solid var(--border);
    padding:14px 32px;

    display:flex;
    align-items:center;
    justify-content:space-between;

    margin:24px -32px 0;
}

.save-bar-info {
    font-size:.85rem;
    color:var(--muted);
}

.save-bar-info strong {
    color:var(--slate);
}


/* =========================================================
   EMPTY
   ========================================================= */

.empty-state {
    padding:30px;
    text-align:center;
    color:var(--muted);
}

.toast{
    position:fixed;
    top:80px;
    right:25px;
    min-width:320px;
    max-width:380px;
    padding:15px 20px;
    border-radius:12px;
    color:#fff;
    font-size:15px;
    font-weight:600;
    background:#16a34a;
    opacity:0;
    visibility:hidden;
    transform:translateX(120%);
    transition:.35s ease;
    z-index:999999;
    box-shadow:0 10px 25px rgba(0,0,0,.25);
}

.toast.show{
    opacity:1;
    visibility:visible;
    transform:translateX(0);
}

.toast.success{
    background:#16a34a;
}

.toast.error{
    background:#dc2626;
}

.toast.warning{
    background:#d97706;
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
/* =========================================================
   MOBILE
   ========================================================= */

@media(max-width:700px) {

    .sem-grid {
        grid-template-columns:repeat(2,1fr);
    }

    .attend-row {
        grid-template-columns:30px 1fr;
    }

    .toggle-wrap {
        grid-column:2;
        width:max-content;
    }

    .save-bar {
        flex-direction:column;
        gap:12px;
        align-items:flex-start;
    }
}

</style>

</head>


<body>


<!-- =====================================================
     TOP BAR
     ===================================================== -->

<div class="topbar">

    <div class="topbar-logo">
        🎓
    </div>

    <span class="topbar-brand">
        EduTrack
    </span>

    <span class="topbar-page">
        Mark Attendance
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
    data-active="attendance">
</div>


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
        <polyline points="9 18 15 12 9 6"/>
    </svg>

    <span>
        Mark Attendance
    </span>

</div>


<div class="page-header">

    <h1 class="page-title">
        Mark Attendance
    </h1>

    <p class="page-sub">
        Select semester → class → mark student attendance
    </p>

</div>



<!-- =====================================================
     STEP 1 : SEMESTER
     ===================================================== -->

<div
    class="flow-step active"
    id="step1"
>

<div
    class="alert alert-info"
    style="max-width:600px;margin-bottom:20px"
>

    <svg viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>

    <span>
        <strong>Step 1 of 3:</strong>
        Select the semester to proceed
    </span>

</div>


<div
    style="
        font-family:'DM Serif Display',serif;
        font-size:1rem;
        color:var(--slate);
        margin-bottom:14px;
    "
>
    Choose Semester
</div>


<div class="sem-grid">


<div
    class="sem-btn"
    onclick="pickSem(1,this)"
>
    <div class="sem-num">I</div>
    <div class="sem-label">Semester</div>
</div>


<div
    class="sem-btn"
    onclick="pickSem(2,this)"
>
    <div class="sem-num">II</div>
    <div class="sem-label">Semester</div>
</div>


<div
    class="sem-btn"
    onclick="pickSem(3,this)"
>
    <div class="sem-num">III</div>
    <div class="sem-label">Semester</div>
</div>


<div
    class="sem-btn"
    onclick="pickSem(4,this)"
>
    <div class="sem-num">IV</div>
    <div class="sem-label">Semester</div>
</div>


<div
    class="sem-btn"
    onclick="pickSem(5,this)"
>
    <div class="sem-num">V</div>
    <div class="sem-label">Semester</div>
</div>


<div
    class="sem-btn"
    onclick="pickSem(6,this)"
>
    <div class="sem-num">VI</div>
    <div class="sem-label">Semester</div>
</div>


<div
    class="sem-btn"
    onclick="pickSem(7,this)"
>
    <div class="sem-num">VII</div>
    <div class="sem-label">Semester</div>
</div>


<div
    class="sem-btn"
    onclick="pickSem(8,this)"
>
    <div class="sem-num">VIII</div>
    <div class="sem-label">Semester</div>
</div>


</div>

</div>



<!-- =====================================================
     STEP 2 : CLASS
     ===================================================== -->

<div
    class="flow-step"
    id="step2"
>

<div
    class="alert alert-info"
    style="max-width:600px;margin-bottom:20px"
>

    <span>
        <strong>Step 2 of 3:</strong>
        Select a class
    </span>

</div>


<div
    style="
        display:flex;
        align-items:center;
        justify-content:space-between;
        margin-bottom:16px;
    "
>

<div
    style="
        font-family:'DM Serif Display',serif;
        font-size:1rem;
        color:var(--slate);
    "
>

    Choose Class –

    <span id="semLabel2"></span>

</div>


<button
    type="button"
    class="btn btn-ghost btn-sm"
    onclick="goStep(1)"
>
    ← Back
</button>

</div>



<div class="class-list" id="classList">


<?php if (!empty($classes)) { ?>


<?php foreach ($classes as $class) { ?>


<div
    class="class-pill attendance-class"

    data-semester="<?= (int) $class['semester'] ?>"

    onclick='pickClass(
        <?= (int) $class["class_id"] ?>,
        <?= json_encode(
            $class["class_name"],
            JSON_HEX_TAG |
            JSON_HEX_APOS |
            JSON_HEX_AMP |
            JSON_HEX_QUOT
        ) ?>,
        <?= json_encode(
            $class["subject_name"] ?? $class["class_name"],
            JSON_HEX_TAG |
            JSON_HEX_APOS |
            JSON_HEX_AMP |
            JSON_HEX_QUOT
        ) ?>,
        <?= json_encode(
            $class["academic_year"],
            JSON_HEX_TAG |
            JSON_HEX_APOS |
            JSON_HEX_AMP |
            JSON_HEX_QUOT
        ) ?>
    )'
>


<div class="class-pill-name">

    <?= htmlspecialchars(
        $class['class_name']
    ) ?>

</div>


<div class="class-pill-info">

    <?= htmlspecialchars(
        $class['department']
    ) ?>

    ·

    Semester
    <?= (int) $class['semester'] ?>

    <br>

    <span class="subject-name">

        <?= htmlspecialchars(
            $class['subject_name']
            ?? $class['class_name']
        ) ?>

    </span>

    ·

    <?= (int) $class['student_count'] ?>
    students

</div>


</div>


<?php } ?>


<?php } else { ?>


<div class="empty-state">
    No classes available.
</div>


<?php } ?>


</div>

</div>



<!-- =====================================================
     STEP 3 : ATTENDANCE
     ===================================================== -->

<div
    class="flow-step"
    id="step3"
>


<div class="attend-header">


<div>

<button
    type="button"
    class="btn btn-ghost btn-sm"
    onclick="goStep(2)"
    style="margin-bottom:8px"
>
    ← Back
</button>


<div
    id="classTitle"
    style="
        font-family:'DM Serif Display',serif;
        font-size:1.1rem;
        color:var(--slate);
    "
>
    Class
</div>


<div
    id="semTitle"
    style="
        font-size:.82rem;
        color:var(--muted);
    "
>
</div>


</div>



<div class="attend-info">


<div>

<label
    class="form-label"
    style="margin-bottom:4px"
>
    Attendance Date
</label>


<input
    type="date"
    class="form-control"
    id="attendDate"
    style="width:180px"
>


</div>


</div>


</div>



<!-- QUICK ACTIONS -->

<div
    style="
        display:flex;
        gap:10px;
        margin-bottom:16px;
        flex-wrap:wrap;
    "
>


<button
    type="button"
    class="btn btn-success btn-sm"
    onclick="markAll('present')"
>
    ✓ Mark All Present
</button>


<button
    type="button"
    class="btn btn-danger btn-sm"
    onclick="markAll('absent')"
>
    ✗ Mark All Absent
</button>


</div>



<!-- SUMMARY -->

<div class="attend-summary">


<div class="sum-chip sum-total">

    Total:

    <span id="sumTotal">
        0
    </span>

</div>


<div class="sum-chip sum-present">

    Present:

    <span id="sumPresent">
        0
    </span>

</div>


<div class="sum-chip sum-absent">

    Absent:

    <span id="sumAbsent">
        0
    </span>

</div>


</div>



<!-- STUDENTS -->

<div
    class="attend-table-wrap"
    id="attendTableWrap"
>

<div class="empty-state">
    Select a class to load students.
</div>

</div>



<!-- SAVE BAR -->

<div class="save-bar">


<div class="save-bar-info">

<strong id="saveInfo">
    0 present, 0 absent
</strong>

— Ready to save

</div>


<button
    type="button"
    class="btn btn-primary"
    id="saveAttendanceBtn"
    onclick="saveAttendance()"
>

<svg viewBox="0 0 24 24">
    <polyline points="20 6 9 17 4 12"/>
</svg>

Save Attendance

</button>


</div>


</div>


</div>
</div>


<script src="shared.js"></script>


<script>

/* =========================================================
   VARIABLES
   ========================================================= */

let students = [];

let attendance = {};

let selectedSemester = null;

let selectedClassId = null;

let selectedClassName = '';

let selectedSubjectName = '';

let selectedAcademicYear = '';



/* =========================================================
   GO STEP
   ========================================================= */

function goStep(n) {

    document
        .querySelectorAll('.flow-step')
        .forEach(step => {

            step.classList.remove('active');

        });


    const target =
        document.getElementById(
            'step' + n
        );


    if (target) {

        target.classList.add('active');

    }
}



/* =========================================================
   SELECT SEMESTER
   ========================================================= */

function pickSem(n, el) {


    selectedSemester = Number(n);


    document
        .querySelectorAll('.sem-btn')
        .forEach(button => {

            button.classList.remove('active');

        });


    el.classList.add('active');


    document.getElementById(
        'semLabel2'
    ).textContent =
        'Semester ' + n;


    /* Filter classes */

    const classCards =
        document.querySelectorAll(
            '.attendance-class'
        );


    let visibleClasses = 0;


    classCards.forEach(card => {

        const classSemester =
            Number(
                card.dataset.semester
            );


        if (
            classSemester ===
            selectedSemester
        ) {

            card.style.display = '';

            visibleClasses++;

        } else {

            card.style.display =
                'none';

        }

    });


    /*
     * Optional message when semester
     * has no classes.
     */

    let empty =
        document.getElementById(
            'semesterEmpty'
        );


    if (!empty) {

        empty =
            document.createElement(
                'div'
            );

        empty.id =
            'semesterEmpty';

        empty.className =
            'empty-state';

        document
            .getElementById(
                'classList'
            )
            .appendChild(empty);
    }


    if (visibleClasses === 0) {

        empty.textContent =
            'No classes available for Semester ' +
            n +
            '.';

        empty.style.display =
            'block';

    } else {

        empty.style.display =
            'none';

    }


    goStep(2);
}



/* =========================================================
   SELECT CLASS
   ========================================================= */

async function pickClass(
    classId,
    className,
    subjectName,
    academicYear
) {


    selectedClassId =
        Number(classId);

    selectedClassName =
        className;

    selectedSubjectName =
        subjectName || className;

    selectedAcademicYear =
        academicYear;


    document.getElementById(
        'classTitle'
    ).textContent =
        selectedClassName;


    document.getElementById(
        'semTitle'
    ).textContent =
        'Semester ' +
        selectedSemester +
        ' · ' +
        selectedSubjectName +
        ' · ' +
        selectedAcademicYear;


    /*
     * Set today's local date
     */

    const now =
        new Date();

    const localDate =
        new Date(
            now.getTime() -
            now.getTimezoneOffset() *
            60000
        )
        .toISOString()
        .split('T')[0];


    document.getElementById(
        'attendDate'
    ).value =
        localDate;


    students = [];

    attendance = {};


    document.getElementById(
        'attendTableWrap'
    ).innerHTML =
        '<div class="empty-state">Loading students...</div>';


    updateSummary();


    goStep(3);


    try {


        const response =
            await fetch(
                'mark_attendance.php?action=get_students&class_id=' +
                encodeURIComponent(
                    selectedClassId
                )
            );


        /*
         * Read text first.
         * This gives a useful error if PHP
         * accidentally returns HTML.
         */

        const responseText =
            await response.text();


        let data;


        try {

            data =
                JSON.parse(
                    responseText
                );

        } catch (e) {

            console.error(
                responseText
            );

            throw new Error(
                'Server returned an invalid response. Check the PHP error.'
            );
        }


        if (!data.success) {

            throw new Error(
                data.message ||
                'Unable to load students.'
            );

        }


        students =
            data.students || [];


        attendance = {};


        students.forEach(
            student => {

                attendance[
                    student.id
                ] = 'present';

            }
        );


        renderAttendTable();


    } catch (error) {


        console.error(error);


        students = [];

        attendance = {};


        document.getElementById(
            'attendTableWrap'
        ).innerHTML =
            '<div class="empty-state">' +
            escapeHtml(
                error.message ||
                'Unable to load students.'
            ) +
            '</div>';


        updateSummary();

    }
}



/* =========================================================
   RENDER STUDENTS
   ========================================================= */

function renderAttendTable() {


    const wrap =
        document.getElementById(
            'attendTableWrap'
        );


    if (students.length === 0) {


        wrap.innerHTML =
            '<div class="empty-state">No students are assigned to this class.</div>';


        updateSummary();

        return;
    }


    const rows =
        students.map(
            (student, index) => {


                const status =
                    attendance[
                        student.id
                    ] || 'present';


                return `

                <div
                    class="attend-row${status === 'absent' ? ' absent-row' : ''}"
                    id="row${student.id}"
                >

                    <div class="row-num">
                        ${index + 1}
                    </div>


                    <div>

                        <div class="row-name">
                            ${escapeHtml(student.name)}
                        </div>

                        <div class="row-roll">
                            ${escapeHtml(student.roll || '')}
                        </div>

                    </div>


                    <div class="toggle-wrap">


                        <button
                            type="button"
                            class="toggle-btn present${status === 'present' ? ' active' : ''}"
                            onclick="setStatus(${student.id}, 'present')"
                        >
                            Present
                        </button>


                        <button
                            type="button"
                            class="toggle-btn absent${status === 'absent' ? ' active' : ''}"
                            onclick="setStatus(${student.id}, 'absent')"
                        >
                            Absent
                        </button>


                    </div>


                </div>

                `;

            }
        )
        .join('');


    wrap.innerHTML =
        rows;


    updateSummary();
}



/* =========================================================
   SET STATUS
   ========================================================= */

function setStatus(
    studentId,
    status
) {


    attendance[
        studentId
    ] = status;


    const row =
        document.getElementById(
            'row' + studentId
        );


    if (!row) {
        return;
    }


    row.classList.toggle(
        'absent-row',
        status === 'absent'
    );


    const presentButton =
        row.querySelector(
            '.toggle-btn.present'
        );


    const absentButton =
        row.querySelector(
            '.toggle-btn.absent'
        );


    presentButton
        .classList
        .toggle(
            'active',
            status === 'present'
        );


    absentButton
        .classList
        .toggle(
            'active',
            status === 'absent'
        );


    updateSummary();
}



/* =========================================================
   MARK ALL
   ========================================================= */

function markAll(status) {


    students.forEach(
        student => {

            attendance[
                student.id
            ] = status;

        }
    );


    renderAttendTable();
}



/* =========================================================
   SUMMARY
   ========================================================= */

function updateSummary() {


    let present = 0;

    let absent = 0;


    students.forEach(
        student => {


            if (
                attendance[
                    student.id
                ] === 'absent'
            ) {

                absent++;

            } else {

                present++;

            }

        }
    );


    document.getElementById(
        'sumTotal'
    ).textContent =
        students.length;


    document.getElementById(
        'sumPresent'
    ).textContent =
        present;


    document.getElementById(
        'sumAbsent'
    ).textContent =
        absent;


    document.getElementById(
        'saveInfo'
    ).textContent =
        `${present} present, ${absent} absent`;
}
function showToast(message, type = "success") {

    const toast = document.getElementById("toast");

    toast.className = "toast";
    toast.classList.add(type);

    toast.innerHTML = message;

    toast.classList.add("show");

    console.log("Added:", toast.className);

    // Keep it visible for testing
    clearTimeout(window.toastTimer);

    window.toastTimer = setTimeout(() => {
    toast.classList.remove("show");
}, 2000);
}
/* =========================================================
   SAVE ATTENDANCE
   ========================================================= */

async function saveAttendance() {


    if (!selectedClassId) {

        showToast("Please select a class.","warning");
        return;
    }


    const date =
        document.getElementById(
            'attendDate'
        ).value;


    if (!date) {

       showToast("Please select an attendance date.","warning");

        return;
    }


    if (students.length === 0) {

        showToast("Please select an attendance date.","warning");

        return;
    }


    const attendanceToSave = {};


    students.forEach(
        student => {


            attendanceToSave[
                student.id
            ] =
                attendance[
                    student.id
                ] === 'absent'
                    ? 'Absent'
                    : 'Present';

        }
    );


    const saveButton =
        document.getElementById(
            'saveAttendanceBtn'
        );


    const oldText =
        saveButton.innerHTML;


    saveButton.disabled =
        true;


    saveButton.textContent =
        'Saving...';


    try {


        const response =
            await fetch(
                'mark_attendance.php?action=save_attendance',
                {

                    method:'POST',

                    headers:{
                        'Content-Type':
                            'application/json'
                    },

                    body:JSON.stringify({

                        class_id:
                            selectedClassId,

                        date:
                            date,

                        attendance:
                            attendanceToSave

                    })

                }
            );


        const responseText =
            await response.text();


        let data;


        try {

            data =
                JSON.parse(
                    responseText
                );

        } catch (e) {

            console.error(
                responseText
            );

            throw new Error(
                'Server returned an invalid response. Check the PHP error.'
            );
        }


        if (!data.success) {

            throw new Error(
                data.message ||
                'Unable to save attendance.'
            );

        }


        showToast("Attendance saved successfully.","success");


    } catch (error) {


        console.error(error);


        showToast(
    error.message || "Unable to save attendance.",
    "error"
);


    } finally {


        saveButton.disabled =
            false;


        saveButton.innerHTML =
            oldText;

    }
}



/* =========================================================
   HTML ESCAPE
   ========================================================= */

function escapeHtml(value) {


    return String(
        value ?? ''
    )

    .replaceAll(
        '&',
        '&amp;'
    )

    .replaceAll(
        '<',
        '&lt;'
    )

    .replaceAll(
        '>',
        '&gt;'
    )

    .replaceAll(
        '"',
        '&quot;'
    )

    .replaceAll(
        "'",
        '&#039;'
    );
}

</script>

<div id="toast" class="toast"></div>
</body>
</html>