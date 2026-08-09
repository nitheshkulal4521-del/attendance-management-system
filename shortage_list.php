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
   FILTER VALUES
   ========================================================= */

$selected_semester = isset($_GET['semester'])
    ? (int)$_GET['semester']
    : 0;

$selected_class = isset($_GET['class_id'])
    ? (int)$_GET['class_id']
    : 0;

$threshold = isset($_GET['threshold'])
    ? (int)$_GET['threshold']
    : 75;

/*
 * Only allow the thresholds provided by the UI.
 */
if (!in_array($threshold, [75, 65, 60], true)) {
    $threshold = 75;
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

$class_stmt->bind_param("i", $teacher_id);
$class_stmt->execute();

$class_result = $class_stmt->get_result();

$teacher_classes = [];
$semesters = [];

while ($row = $class_result->fetch_assoc()) {

    $teacher_classes[] = $row;

    $sem = (int)$row['semester'];

    if (!in_array($sem, $semesters, true)) {
        $semesters[] = $sem;
    }
}

$class_stmt->close();

sort($semesters);


/* =========================================================
   VALIDATE SELECTED CLASS
   ========================================================= */

if ($selected_class > 0) {

    $valid_class = false;

    foreach ($teacher_classes as $class) {

        if ((int)$class['class_id'] === $selected_class) {

            /*
             * If semester is also selected,
             * class must belong to that semester.
             */
            if (
                $selected_semester === 0 ||
                (int)$class['semester'] === $selected_semester
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
   SHORTAGE QUERY
   ========================================================= */

/*
 * We start from class_students because this is now the
 * official student-class relationship.
 *
 * We count class sessions for each class and attendance
 * records belonging to each student.
 */

$sql = "
    SELECT
        st.student_id,
        st.roll_no,
        st.student_name,

        c.class_id,
        c.class_name,
        c.department,
        c.semester,

        COUNT(
    DISTINCT sess.session_id
) AS total_classes,

/*
 * Effective Present =
 * Actual Present + Granted Grace
 */
COUNT(
    DISTINCT CASE
        WHEN
            a.status = 'Present'
            OR ga.status = 'Granted'
        THEN sess.session_id
    END
) AS present_count,

/*
 * Remaining absent:
 * Absent records that have NOT received grace.
 */
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

/*
 * Number of absences converted to effective
 * attendance through grace.
 */
COUNT(
    DISTINCT CASE
        WHEN ga.status = 'Granted'
        THEN sess.session_id
    END
) AS grace_count

    FROM classes c

    INNER JOIN class_students cst
        ON cst.class_id = c.class_id

    INNER JOIN students st
        ON st.student_id = cst.student_id

    LEFT JOIN class_sessions sess
        ON sess.class_id = c.class_id

    LEFT JOIN attendance a
    ON a.session_id = sess.session_id
   AND a.student_id = st.student_id

LEFT JOIN grace_attendance ga
    ON ga.attendance_id = a.attendance_id
   AND ga.status = 'Granted'

WHERE c.teacher_id = ?
";

$types = "i";
$params = [$teacher_id];


/* Semester filter */

if ($selected_semester > 0) {

    $sql .= "
        AND c.semester = ?
    ";

    $types .= "i";
    $params[] = $selected_semester;
}


/* Class filter */

if ($selected_class > 0) {

    $sql .= "
        AND c.class_id = ?
    ";

    $types .= "i";
    $params[] = $selected_class;
}


$sql .= "
    GROUP BY
        st.student_id,
        st.roll_no,
        st.student_name,
        c.class_id,
        c.class_name,
        c.department,
        c.semester

    ORDER BY
        c.semester ASC,
        c.class_name ASC,
        st.roll_no ASC
";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$result = $stmt->get_result();


/* =========================================================
   BUILD SHORTAGE ARRAY
   ========================================================= */

$shortage_students = [];

while ($row = $result->fetch_assoc()) {

    $total   = (int)$row['total_classes'];
$present = (int)$row['present_count'];
$absent  = (int)$row['absent_count'];
$grace   = (int)$row['grace_count'];

    /*
     * Do not mark students as shortage when
     * the class has no attendance sessions yet.
     */
    if ($total <= 0) {
        continue;
    }

    $percentage = round(
        ($present / $total) * 100,
        1
    );


    /*
     * Only students below selected threshold.
     */
    if ($percentage >= $threshold) {
        continue;
    }


    /* =====================================================
       CLASSES NEEDED

       Find minimum x such that:

       (present + x) / (total + x) >= 0.75

       Shortage recovery always uses 75% because 75%
       is the actual attendance requirement.

       Example:
       28 / 40 = 70%

       x = 8

       36 / 48 = 75%
       ===================================================== */

    $classes_needed = 0;

    if ($percentage < 75) {

        $classes_needed = (int)ceil(
            ((0.75 * $total) - $present)
            / 0.25
        );

        if ($classes_needed < 0) {
            $classes_needed = 0;
        }
    }


    $row['total_classes']  = $total;
$row['present_count']  = $present;
$row['absent_count']   = $absent;
$row['grace_count']    = $grace;
$row['percentage']     = $percentage;
$row['classes_needed'] = $classes_needed;

    $shortage_students[] = $row;
}

$stmt->close();


/* =========================================================
   SUMMARY STATISTICS
   ========================================================= */

$total_shortage = count($shortage_students);

$critical_count = 0;
$warning_count  = 0;

foreach ($shortage_students as $student) {

    $pct = $student['percentage'];

    if ($pct < 60) {

        $critical_count++;

    } elseif ($pct < 75) {

        $warning_count++;
    }
}


/* =========================================================
   EXPORT CSV
   ========================================================= */

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'csv'
) {

    $filename =
        "attendance_shortage_" .
        date("Y-m-d") .
        ".csv";

    header(
        'Content-Type: text/csv; charset=utf-8'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    $output = fopen(
        'php://output',
        'w'
    );

    /*
     * UTF-8 BOM for Excel.
     */
    fwrite(
        $output,
        "\xEF\xBB\xBF"
    );

    fputcsv(
        $output,
        [
            'Roll Number',
            'Student Name',
            'Class / Subject',
            'Department',
            'Semester',
            'Total Classes',
            'Present',
            'Absent',
            'Attendance Percentage',
            'Classes Needed'
        ]
    );

    foreach ($shortage_students as $student) {

        fputcsv(
            $output,
            [
                $student['roll_no'],
                $student['student_name'],
                $student['class_name'],
                $student['department'],
                $student['semester'],
                $student['total_classes'],
                $student['present_count'],
                $student['absent_count'],
                $student['percentage'] . '%',
                $student['classes_needed']
            ]
        );
    }

    fclose($output);
    exit();
}


/* =========================================================
   EXPORT URL
   ========================================================= */

$export_params = $_GET;
$export_params['export'] = 'csv';

$export_url =
    'shortage_list.php?' .
    http_build_query($export_params);

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
    Shortage List – EduTrack ERP
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
.warning-banner{
    background:var(--red-bg);
    border:1.5px solid #fecaca;
    border-radius:14px;
    padding:18px 22px;
    display:flex;
    align-items:center;
    gap:14px;
    margin-bottom:24px;
}

.warning-banner svg{
    width:24px;
    height:24px;
    stroke:var(--red);
    fill:none;
    stroke-width:2;
    flex-shrink:0;
}

.wb-title{
    font-size:0.95rem;
    font-weight:700;
    color:var(--red);
}

.wb-sub{
    font-size:0.82rem;
    color:#991b1b;
    margin-top:2px;
}

.filter-bar{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:16px 20px;
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    align-items:flex-end;
    margin-bottom:20px;
}

.filter-bar label{
    font-size:0.75rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.3px;
    color:var(--muted);
    display:block;
    margin-bottom:5px;
}

.shortage-row td{
    color:#7f1d1d;
}

.shortage-row{
    background:var(--red-bg);
}

.shortage-row:hover{
    background:#fee2e2!important;
}

.classes-needed{
    font-size:0.8rem;
    color:var(--red);
    font-weight:600;
    background:#fee2e2;
    padding:3px 8px;
    border-radius:5px;
    white-space:nowrap;
}

.search-box{
    display:flex;
    align-items:center;
    gap:8px;
    background:#fff;
    border:1.5px solid var(--border);
    border-radius:9px;
    padding:8px 13px;
}

.search-box:focus-within{
    border-color:var(--blue);
}

.search-box svg{
    width:14px;
    height:14px;
    stroke:var(--muted);
    fill:none;
    stroke-width:2;
}

.search-box input{
    border:none;
    outline:none;
    font-family:'DM Sans',sans-serif;
    font-size:0.85rem;
    color:var(--slate);
    background:transparent;
    width:180px;
}

.toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:14px;
    flex-wrap:wrap;
    gap:10px;
}

.summary-chips{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:20px;
}

.sc{
    padding:10px 18px;
    border-radius:10px;
    font-size:0.83rem;
    font-weight:700;
    text-align:center;
}

.sc-red{
    background:var(--red-bg);
    color:var(--red);
    border:1px solid #fecaca;
}

.sc-crit{
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #f87171;
}

.no-shortage{
    text-align:center;
    padding:45px 20px;
    color:var(--muted);
}

.no-shortage-title{
    font-size:1rem;
    font-weight:700;
    color:var(--green);
    margin-bottom:5px;
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

    .filter-bar{
        align-items:stretch;
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
        Shortage List
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
    data-active="shortage"
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
    <polyline points="9 18 15 12 9 6"/>
</svg>

<span>
    Shortage List
</span>

</div>


<!-- =====================================================
     HEADER
     ===================================================== -->

<div
    class="page-header"
    style="
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        flex-wrap:wrap;
        gap:12px
    "
>

<div>

<h1 class="page-title">
    Attendance Shortage List
</h1>

<p class="page-sub">
    Students with attendance below the selected threshold
</p>

</div>


<div style="display:flex;gap:10px">


<a
    href="<?= htmlspecialchars($export_url) ?>"
    class="btn btn-ghost btn-sm"
>

<svg viewBox="0 0 24 24">

<path
    d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"
/>

<polyline
    points="7 10 12 15 17 10"
/>

<line
    x1="12"
    y1="15"
    x2="12"
    y2="3"
/>

</svg>

Export List

</a>


<a
    href="grace_management.php"
    class="btn btn-outline btn-sm"
>

<svg viewBox="0 0 24 24">

<path
    d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"
/>

</svg>

Grant Grace

</a>


</div>

</div>


<!-- =====================================================
     WARNING BANNER
     ===================================================== -->

<?php if ($total_shortage > 0) { ?>

<div class="warning-banner">

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


<div>

<div class="wb-title">
    ⚠ Attendance Shortage Alert
</div>

<div class="wb-sub">

<?= $total_shortage ?>

<?= $total_shortage === 1
    ? 'student is'
    : 'students are' ?>

currently below the selected attendance threshold.

</div>

</div>

</div>

<?php } ?>


<!-- =====================================================
     SUMMARY
     ===================================================== -->

<div class="summary-chips">


<div class="sc sc-red">

Total Shortage Students:

<strong>
    <?= $total_shortage ?>
</strong>

</div>


<div class="sc sc-crit">

Critical (&lt;60%):

<strong>
    <?= $critical_count ?>
</strong>

</div>


<div
    class="sc"
    style="
        background:var(--yellow-bg);
        color:var(--yellow);
        border:1px solid #fde68a
    "
>

Between 60%–74%:

<strong>
    <?= $warning_count ?>
</strong>

</div>


</div>


<!-- =====================================================
     FILTERS
     ===================================================== -->

<form
    method="GET"
    class="filter-bar"
>


<!-- Semester -->

<div>

<label>
    Semester
</label>

<select
    name="semester"
    id="semesterFilter"
    class="form-control"
    style="width:150px"
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

<div>

<label>
    Class / Subject
</label>

<select
    name="class_id"
    id="classFilter"
    class="form-control"
    style="width:190px"
>

<option value="0">
    All Classes
</option>

<?php foreach ($teacher_classes as $class) { ?>

<option
    value="<?= (int)$class['class_id'] ?>"
    data-semester="<?= (int)$class['semester'] ?>"
    <?= $selected_class === (int)$class['class_id']
        ? 'selected'
        : '' ?>
>

<?= htmlspecialchars($class['class_name']) ?>

</option>

<?php } ?>

</select>

</div>


<!-- Threshold -->

<div>

<label>
    Threshold
</label>

<select
    name="threshold"
    class="form-control"
    style="width:130px"
>

<option
    value="75"
    <?= $threshold === 75
        ? 'selected'
        : '' ?>
>
    Below 75%
</option>

<option
    value="65"
    <?= $threshold === 65
        ? 'selected'
        : '' ?>
>
    Below 65%
</option>

<option
    value="60"
    <?= $threshold === 60
        ? 'selected'
        : '' ?>
>
    Below 60%
</option>

</select>

</div>


<button
    type="submit"
    class="btn btn-primary btn-sm"
>

Apply Filter

</button>


<a
    href="shortage_list.php"
    class="btn btn-ghost btn-sm"
>

Clear

</a>


</form>


<!-- =====================================================
     TOOLBAR
     ===================================================== -->

<div class="toolbar">


<div class="search-box">

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


<input
    type="text"
    id="studentSearch"
    placeholder="Search students..."
    oninput="filterStudents()"
>

</div>


<div
    id="showingCount"
    style="
        font-size:0.82rem;
        color:var(--muted)
    "
>

<?= $total_shortage ?>

<?= $total_shortage === 1
    ? 'student'
    : 'students' ?>

in shortage

</div>


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

<th>Roll No.</th>

<th>Student Name</th>

<th>Class / Subject</th>

<th>Semester</th>

<th>Total</th>

<th>Present</th>

<th>Absent</th>

<th>Attendance %</th>

<th>Classes Needed</th>

<th>Action</th>

</tr>

</thead>


<tbody id="shortageBody">


<?php if ($total_shortage > 0) { ?>


<?php
$sn = 1;

foreach ($shortage_students as $student) {

    $pct =
        (float)$student['percentage'];

    /*
     * Progress bar should stay between 0 and 100.
     */
    $bar_width =
        max(
            0,
            min(
                100,
                $pct
            )
        );

    $detail_url =
        'student_attendance_detail.php?' .
        http_build_query([
            'student_id' =>
                (int)$student['student_id'],

            'class_id' =>
                (int)$student['class_id']
        ]);
?>


<tr
    class="shortage-row student-row"
    data-search="<?= htmlspecialchars(
        strtolower(
            $student['roll_no'] . ' ' .
            $student['student_name'] . ' ' .
            $student['class_name'] . ' ' .
            $student['department']
        )
    ) ?>"
>


<td>
    <?= $sn++ ?>
</td>


<td
    style="
        font-family:monospace;
        font-weight:700;
        font-size:.82rem
    "
>

<?= htmlspecialchars(
    $student['roll_no']
) ?>

</td>


<td style="font-weight:700">

<?= htmlspecialchars(
    $student['student_name']
) ?>

</td>


<td>

<?= htmlspecialchars(
    $student['class_name']
) ?>

</td>


<td>

<?= (int)$student['semester'] ?>

</td>


<td>

<?= (int)$student['total_classes'] ?>

</td>


<td>

<?= (int)$student['present_count'] ?>

</td>


<td>

<?= (int)$student['absent_count'] ?>

</td>


<td style="min-width:150px">

<div
    style="
        display:flex;
        align-items:center;
        gap:8px
    "
>

<div
    style="
        flex:1;
        height:6px;
        background:#fecaca;
        border-radius:10px;
        overflow:hidden
    "
>

<div
    style="
        height:100%;
        width:<?= $bar_width ?>%;
        background:var(--red);
        border-radius:10px
    "
></div>

</div>


<span
    style="
        font-weight:800;
        color:var(--red);
        font-size:.88rem
    "
>

<?= number_format(
    $pct,
    1
) ?>%

</span>


</div>

</td>


<td>

<span class="classes-needed">

+<?= (int)$student['classes_needed'] ?>

<?= (int)$student['classes_needed'] === 1
    ? 'class'
    : 'classes' ?>

</span>

</td>


<td>

<div
    style="
        display:flex;
        gap:5px
    "
>


<a
    href="grace_management.php?student_id=<?= (int)$student['student_id'] ?>&class_id=<?= (int)$student['class_id'] ?>"
    class="btn btn-success btn-xs"
>

Grace

</a>


<a
    href="<?= htmlspecialchars($detail_url) ?>"
    class="btn btn-outline btn-xs"
>

Detail

</a>


</div>

</td>


</tr>


<?php } ?>


<?php } else { ?>


<tr id="noShortageRow">

<td
    colspan="11"
    class="no-shortage"
>

<div class="no-shortage-title">
    No Attendance Shortage
</div>

<div>
    No students are below
    <?= $threshold ?>%
    for the selected filters.
</div>

</td>

</tr>


<?php } ?>


<tr
    id="noSearchResults"
    style="display:none"
>

<td
    colspan="11"
    class="no-shortage"
>

No students match your search.

</td>

</tr>


</tbody>

</table>

</div>

</div>


<!-- =====================================================
     NOTE
     ===================================================== -->

<div
    class="alert alert-info"
    style="margin-top:18px"
>

<svg viewBox="0 0 24 24">

<circle
    cx="12"
    cy="12"
    r="10"
/>

<line
    x1="12"
    y1="8"
    x2="12"
    y2="12"
/>

<line
    x1="12"
    y1="16"
    x2="12.01"
    y2="16"
/>

</svg>


<span>

<strong>Note:</strong>

"Classes Needed" indicates the minimum number of consecutive
classes the student must attend to reach 75% attendance,
assuming no further absences.

</span>


</div>


</div>

</div>


<script src="shared.js"></script>

<script>

/* =========================================================
   FILTER CLASS OPTIONS BY SEMESTER
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

        const optionSemester =
            option.dataset.semester;

        const visible =
            semester === '0' ||
            optionSemester === semester;

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
   SEARCH
   ========================================================= */

function filterStudents() {

    const input =
        document.getElementById(
            'studentSearch'
        );

    const search =
        input.value
            .toLowerCase()
            .trim();

    const rows =
        document.querySelectorAll(
            '.student-row'
        );

    let visibleCount = 0;

    rows.forEach(row => {

        const text =
            row.dataset.search || '';

        const visible =
            text.includes(search);

        row.style.display =
            visible
                ? ''
                : 'none';

        if (visible) {
            visibleCount++;
        }

    });


    const count =
        document.getElementById(
            'showingCount'
        );

    count.textContent =
        visibleCount +
        (
            visibleCount === 1
                ? ' student in shortage'
                : ' students in shortage'
        );


    const noResults =
        document.getElementById(
            'noSearchResults'
        );

    if (noResults) {

        noResults.style.display =
            rows.length > 0 &&
            visibleCount === 0
                ? ''
                : 'none';
    }

}


/* Run once when page loads */

filterClassOptions();

</script>


</body>

</html>