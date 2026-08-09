<?php

session_start();
require 'db_connect.php';

/* =========================================================
   LOGIN
   ========================================================= */

if (!isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login.php");
    exit();
}

/*
 * Your login stores users.user_id inside
 * $_SESSION['teacher_id']
 */
$user_id = (int)$_SESSION['teacher_id'];


/* =========================================================
   GET ACTUAL TEACHER
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
   FILTER VALUES
   ========================================================= */

$semester = isset($_GET['semester'])
    ? (int)$_GET['semester']
    : 0;

$class_id = isset($_GET['class_id'])
    ? (int)$_GET['class_id']
    : 0;

$from_date = trim($_GET['from_date'] ?? '');
$to_date = trim($_GET['to_date'] ?? '');

$status_filter = $_GET['status'] ?? 'all';


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
        s.subject_name
    FROM classes c

    LEFT JOIN class_subjects cs
        ON cs.class_id = c.class_id

    LEFT JOIN subjects s
        ON s.subject_id = cs.subject_id

    WHERE c.teacher_id = ?

    ORDER BY
        c.semester,
        c.class_name
");

$class_stmt->bind_param("i", $teacher_id);
$class_stmt->execute();

$class_result = $class_stmt->get_result();

$classes = [];

while ($row = $class_result->fetch_assoc()) {
    $classes[] = $row;
}

$class_stmt->close();


/* =========================================================
   REPORT DATA
   ========================================================= */

$report = [];

$total_students = 0;
$total_classes = 0;
$above_75 = 0;
$below_75 = 0;
$class_average = 0;

$selected_class = null;


/*
 * Generate only when a class is selected.
 */
if ($class_id > 0) {

    /* Verify class belongs to teacher */

    $verify = $conn->prepare("
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

    $verify->bind_param(
        "ii",
        $class_id,
        $teacher_id
    );

    $verify->execute();

    $selected_class =
        $verify->get_result()->fetch_assoc();

    $verify->close();


    if ($selected_class) {

        /*
         * Build date conditions.
         */

        $date_conditions = "";
        $types = "i";
        $params = [$class_id];

        if ($from_date !== '') {
            $date_conditions .= "
                AND cs.class_date >= ?
            ";

            $types .= "s";
            $params[] = $from_date;
        }

        if ($to_date !== '') {
            $date_conditions .= "
                AND cs.class_date <= ?
            ";

            $types .= "s";
            $params[] = $to_date;
        }


        /* =================================================
           TOTAL CLASSES / SESSIONS
           ================================================= */

        $sql = "
            SELECT COUNT(*) AS total
            FROM class_sessions cs
            WHERE cs.class_id = ?
            $date_conditions
        ";

        $session_stmt =
            $conn->prepare($sql);

        $session_stmt->bind_param(
            $types,
            ...$params
        );

        $session_stmt->execute();

        $session_data =
            $session_stmt
                ->get_result()
                ->fetch_assoc();

        $total_classes =
            (int)$session_data['total'];

        $session_stmt->close();


        /* =================================================
           STUDENT REPORT
           ================================================= */

       $report_sql = "
    SELECT
        st.student_id,
        st.roll_no,
        st.student_name,

        COUNT(
            DISTINCT cs.session_id
        ) AS total_classes,

        COUNT(
            DISTINCT CASE
                WHEN
                    a.status = 'Present'
                    OR ga.status = 'Granted'
                THEN cs.session_id
            END
        ) AS present_count,

        COUNT(
            DISTINCT CASE
                WHEN
                    a.status = 'Absent'
                    AND (
                        ga.status IS NULL
                        OR ga.status <> 'Granted'
                    )
                THEN cs.session_id
            END
        ) AS absent_count,

        COUNT(
            DISTINCT CASE
                WHEN ga.status = 'Granted'
                THEN cs.session_id
            END
        ) AS grace_count

    FROM class_students cst

    INNER JOIN students st
        ON st.student_id = cst.student_id

    LEFT JOIN class_sessions cs
        ON cs.class_id = cst.class_id
        $date_conditions

    LEFT JOIN attendance a
        ON a.session_id = cs.session_id
       AND a.student_id = st.student_id

    LEFT JOIN grace_attendance ga
        ON ga.attendance_id = a.attendance_id
       AND ga.status = 'Granted'

    WHERE cst.class_id = ?

    GROUP BY
        st.student_id,
        st.roll_no,
        st.student_name

    ORDER BY
        st.roll_no,
        st.student_name
";
        /*
         * Date parameters appear before
         * WHERE st.class_id in this query.
         */

        $report_types = "";
        $report_params = [];

        if ($from_date !== '') {
            $report_types .= "s";
            $report_params[] = $from_date;
        }

        if ($to_date !== '') {
            $report_types .= "s";
            $report_params[] = $to_date;
        }

        $report_types .= "i";
        $report_params[] = $class_id;


        $report_stmt =
            $conn->prepare($report_sql);

        $report_stmt->bind_param(
            $report_types,
            ...$report_params
        );

        $report_stmt->execute();

        $result =
            $report_stmt->get_result();


        $percentage_total = 0;

        while ($row = $result->fetch_assoc()) {

            $student_total =
                (int)$row['total_classes'];

            $present =
                (int)$row['present_count'];

            $absent =
                (int)$row['absent_count'];
            $grace =
    (int)$row['grace_count'];    


            if ($student_total > 0) {

                $percentage =
                    ($present / $student_total) * 100;

            } else {

                $percentage = 0;
            }


            $percentage =
                round($percentage, 1);


            /*
             * Status filter
             */

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


            /*
             * Overall statistics should use
             * all students, not only filtered rows.
             */

            $total_students++;

            $percentage_total +=
                $percentage;

            if ($percentage >= 75) {
                $above_75++;
            } else {
                $below_75++;
            }


            if ($include) {

                $report[] = [
    'student_id' =>
        (int)$row['student_id'],

    'roll_no' =>
        $row['roll_no'],

    'student_name' =>
        $row['student_name'],

    'total' =>
        $student_total,

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

        $report_stmt->close();


        if ($total_students > 0) {

            $class_average =
                round(
                    $percentage_total /
                    $total_students,
                    1
                );
        }
        /* =========================================================
   EXPORT CSV / EXCEL
   ========================================================= */

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'excel'
) {

    $filename =
        'attendance_report_' .
        date('Y-m-d_H-i-s') .
        '.csv';


    header(
        'Content-Type: text/csv; charset=utf-8'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );


    $output =
        fopen('php://output', 'w');


    /*
     * UTF-8 BOM helps Excel display
     * names correctly.
     */

    fwrite(
        $output,
        "\xEF\xBB\xBF"
    );


    /* Report information */

    fputcsv($output, [
        'Attendance Report'
    ]);

    fputcsv($output, [
        'Class',
        $selected_class['class_name']
    ]);

    fputcsv($output, [
        'Subject',
        $selected_class['subject_name'] ?? ''
    ]);

    fputcsv($output, [
        'Semester',
        $selected_class['semester']
    ]);

    fputcsv($output, [
        'Department',
        $selected_class['department']
    ]);

    if ($from_date !== '') {

        fputcsv($output, [
            'From Date',
            $from_date
        ]);
    }

    if ($to_date !== '') {

        fputcsv($output, [
            'To Date',
            $to_date
        ]);
    }


    /* Blank row */

    fputcsv($output, []);


    /* Column headings */

    fputcsv($output, [
        '#',
        'Roll No.',
        'Student Name',
        'Total Classes',
        'Effective Present',
        'Absent',
        'Grace',
        'Attendance %',
        'Status'
    ]);


    $number = 1;


    foreach ($report as $row) {

        $percentage =
            (float)$row['percentage'];


        if ($percentage >= 85) {

            $status =
                'Good';

        } elseif ($percentage >= 75) {

            $status =
                'Warning';

        } else {

            $status =
                'Shortage';
        }


        fputcsv($output, [

            $number++,

            $row['roll_no'],

            $row['student_name'],

            $row['total'],

            $row['present'],

            $row['absent'],

            $row['grace'],

            number_format(
                $percentage,
                1
            ) . '%',

            $status

        ]);
    }


    fclose($output);

    exit();
}
    }
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
    Attendance Report – EduTrack ERP
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

.filter-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    padding:20px;
    display:flex;
    gap:14px;
    flex-wrap:wrap;
    align-items:flex-end;
    margin-bottom:24px
}

.filter-group{
    display:flex;
    flex-direction:column;
    gap:6px;
    min-width:150px
}

.filter-group label{
    font-size:.75rem;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.3px;
    color:var(--muted)
}

.stats-strip{
    display:grid;
    grid-template-columns:repeat(5,1fr);
    gap:12px;
    margin-bottom:24px
}

.strip-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:16px 18px;
    text-align:center
}

.strip-val{
    font-family:'DM Serif Display',serif;
    font-size:1.5rem;
    color:var(--slate)
}

.strip-key{
    font-size:.72rem;
    color:var(--muted);
    margin-top:3px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.3px
}

.pct-bar{
    width:100%;
    height:5px;
    background:var(--border);
    border-radius:10px;
    overflow:hidden;
    margin-top:6px
}

.pct-fill{
    height:100%;
    border-radius:10px
}

.pct-fill.high{
    background:var(--green)
}

.pct-fill.mid{
    background:var(--yellow)
}

.pct-fill.low{
    background:var(--red)
}

.pct-text{
    font-weight:700;
    font-size:.88rem
}

.pct-text.high{
    color:var(--green)
}

.pct-text.mid{
    color:var(--yellow)
}

.pct-text.low{
    color:var(--red)
}

.row-low{
    background:var(--red-bg)!important
}

.row-mid{
    background:var(--yellow-bg)!important
}

.toolbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:14px;
    flex-wrap:wrap;
    gap:10px
}

.search-box{
    display:flex;
    align-items:center;
    gap:8px;
    background:#fff;
    border:1.5px solid var(--border);
    border-radius:9px;
    padding:8px 13px
}

.search-box:focus-within{
    border-color:var(--blue)
}

.search-box input{
    border:none;
    outline:none;
    font-family:'DM Sans',sans-serif;
    font-size:.85rem;
    background:transparent;
    width:180px
}

.legend{
    display:flex;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:14px
}

.legend-item{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:.78rem;
    color:var(--muted)
}

.legend-dot{
    width:10px;
    height:10px;
    border-radius:50%
}

.empty-report{
    text-align:center;
    padding:40px;
    color:var(--muted)
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
@media(max-width:900px){

    .stats-strip{
        grid-template-columns:
            repeat(3,1fr)
    }
}

@media(max-width:600px){

    .stats-strip{
        grid-template-columns:
            repeat(2,1fr)
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
        Attendance Report
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
    data-active="report">
</div>


<div class="main">

<div class="page-content">


<div class="breadcrumb">

<a href="teacher_dashboard.php">
    Dashboard
</a>

<svg viewBox="0 0 24 24">
    <polyline points="9 18 15 12 9 6"/>
</svg>

<span>
    Attendance Report
</span>

</div>


<div
    class="page-header"
    style="
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:15px;
        flex-wrap:wrap;
    "
>

<div>

    <h1 class="page-title">
        Attendance Report
    </h1>

    <p class="page-sub">
        Detailed attendance analytics for students
    </p>

</div>


<?php if ($class_id > 0 && $selected_class): ?>

<div style="display:flex;gap:10px">

    <a
        href="#"
        class="btn btn-outline btn-sm"
        onclick="exportPDF(event)"
    >
        Export PDF
    </a>

    <a
        href="#"
        class="btn btn-outline btn-sm"
        onclick="exportExcel(event)"
    >
        Export Excel
    </a>

</div>

<?php endif; ?>

</div>


<!-- =====================================================
     FILTERS
     ===================================================== -->

<form
    method="GET"
    class="filter-card"
>


<div class="filter-group">

<label>
    Semester
</label>

<select
    name="semester"
    id="semesterFilter"
    class="form-control"
    onchange="filterClasses()"
>

<option value="0">
    All Semesters
</option>

<?php for ($i = 1; $i <= 8; $i++) { ?>

<option
    value="<?= $i ?>"
    <?= $semester === $i ? 'selected' : '' ?>
>
    Semester <?= $i ?>
</option>

<?php } ?>

</select>

</div>


<div class="filter-group">

<label>
    Class / Subject
</label>

<select
    name="class_id"
    id="classFilter"
    class="form-control"
>

<option value="0">
    Select Class
</option>


<?php foreach ($classes as $class) { ?>

<option
    value="<?= (int)$class['class_id'] ?>"
    data-semester="<?= (int)$class['semester'] ?>"
    <?= $class_id === (int)$class['class_id']
        ? 'selected'
        : '' ?>
>

<?= htmlspecialchars(
    $class['subject_name']
    ?: $class['class_name']
) ?>

–
<?= htmlspecialchars(
    $class['department']
) ?>

</option>

<?php } ?>

</select>

</div>


<div class="filter-group">

<label>
    From Date
</label>

<input
    type="date"
    name="from_date"
    class="form-control"
    value="<?= htmlspecialchars($from_date) ?>"
>

</div>


<div class="filter-group">

<label>
    To Date
</label>

<input
    type="date"
    name="to_date"
    class="form-control"
    value="<?= htmlspecialchars($to_date) ?>"
>

</div>


<div class="filter-group">

<label>
    Status
</label>

<select
    name="status"
    class="form-control"
>

<option
    value="all"
    <?= $status_filter === 'all'
        ? 'selected'
        : '' ?>
>
    All Students
</option>

<option
    value="below75"
    <?= $status_filter === 'below75'
        ? 'selected'
        : '' ?>
>
    Below 75%
</option>

<option
    value="75to85"
    <?= $status_filter === '75to85'
        ? 'selected'
        : '' ?>
>
    75% – 85%
</option>

<option
    value="above85"
    <?= $status_filter === 'above85'
        ? 'selected'
        : '' ?>
>
    Above 85%
</option>

</select>

</div>


<button
    type="submit"
    class="btn btn-primary"
>

Generate

</button>


</form>


<!-- =====================================================
     STATISTICS
     ===================================================== -->

<div class="stats-strip">


<div class="strip-card">

<div class="strip-val">
    <?= $total_students ?>
</div>

<div class="strip-key">
    Total Students
</div>

</div>


<div class="strip-card">

<div class="strip-val">
    <?= $total_classes ?>
</div>

<div class="strip-key">
    Total Classes
</div>

</div>


<div class="strip-card">

<div
    class="strip-val"
    style="color:var(--green)"
>
    <?= $above_75 ?>
</div>

<div class="strip-key">
    ≥ 75% Attendance
</div>

</div>


<div class="strip-card">

<div
    class="strip-val"
    style="color:var(--red)"
>
    <?= $below_75 ?>
</div>

<div class="strip-key">
    Below 75%
</div>

</div>


<div class="strip-card">

<div class="strip-val">
    <?= $class_average ?>%
</div>

<div class="strip-key">
    Class Average
</div>

</div>


</div>


<!-- =====================================================
     LEGEND
     ===================================================== -->

<div class="legend">

<div class="legend-item">

<div
    class="legend-dot"
    style="background:var(--green)"
></div>

≥ 85% – Good

</div>


<div class="legend-item">

<div
    class="legend-dot"
    style="background:var(--yellow)"
></div>

75% – 84% – Warning

</div>


<div class="legend-item">

<div
    class="legend-dot"
    style="background:var(--red)"
></div>

&lt; 75% – Shortage

</div>

</div>


<!-- =====================================================
     TOOLBAR
     ===================================================== -->

<div class="toolbar">


<div class="search-box">

<input
    type="text"
    id="studentSearch"
    placeholder="Search student..."
    oninput="searchStudents()"
>

</div>


<div
    style="
        font-size:.82rem;
        color:var(--muted)
    "
>

Showing

<span id="visibleCount">
    <?= count($report) ?>
</span>

students

</div>


</div>


<!-- =====================================================
     REPORT TABLE
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

<th>Total Classes</th>

<th>Effective Present</th>

<th>Absent</th>

<th>Percentage</th>

<th>Status</th>

<th>Action</th>

</tr>

</thead>


<tbody id="reportBody">


<?php if ($class_id <= 0) { ?>


<tr>

<td
    colspan="9"
    class="empty-report"
>

Select a semester and class,
then click Generate.

</td>

</tr>


<?php } elseif (!$selected_class) { ?>


<tr>

<td
    colspan="9"
    class="empty-report"
>

Invalid class selected.

</td>

</tr>


<?php } elseif (empty($report)) { ?>


<tr>

<td
    colspan="9"
    class="empty-report"
>

No attendance records found
for the selected filters.

</td>

</tr>


<?php } else { ?>


<?php
$sn = 1;

foreach ($report as $row) {

    $pct = $row['percentage'];

    if ($pct >= 85) {

        $pct_class = 'high';
        $row_class = '';
        $status_text = 'Good';
        $badge_class = 'badge-green';

    } elseif ($pct >= 75) {

        $pct_class = 'mid';
        $row_class = 'row-mid';
        $status_text = 'Warning';
        $badge_class = 'badge-yellow';

    } else {

        $pct_class = 'low';
        $row_class = 'row-low';
        $status_text = 'Shortage';
        $badge_class = 'badge-red';
    }
?>


<tr
    class="student-report-row <?= $row_class ?>"
    data-search="<?= htmlspecialchars(
        strtolower(
            ($row['roll_no'] ?? '') .
            ' ' .
            $row['student_name']
        )
    ) ?>"
>


<td>
    <?= $sn++ ?>
</td>


<td
    style="
        font-family:monospace;
        font-weight:600;
        color:var(--blue);
        font-size:.82rem
    "
>

<?= htmlspecialchars(
    $row['roll_no'] ?? ''
) ?>

</td>


<td style="font-weight:600">

<?= htmlspecialchars(
    $row['student_name']
) ?>

</td>


<td style="text-align:center">

<?= $row['total'] ?>

</td>


<td
    style="
        text-align:center;
        color:var(--green);
        font-weight:600
    "
>

<?= $row['present'] ?>

</td>


<td
    style="
        text-align:center;
        color:var(--red);
        font-weight:600
    "
>

<?= $row['absent'] ?>

</td>


<td
    class="pct-cell"
    style="min-width:120px"
>

<div
    style="
        display:flex;
        align-items:center;
        gap:8px
    "
>

<div
    class="pct-bar"
    style="flex:1"
>

<div
    class="pct-fill <?= $pct_class ?>"
    style="width:<?= min(100, $pct) ?>%"
></div>

</div>


<span
    class="pct-text <?= $pct_class ?>"
>

<?= number_format($pct, 1) ?>%

</span>


</div>

</td>


<td>

<span
    class="badge <?= $badge_class ?>"
>

<span class="badge-dot"></span>

<?= $status_text ?>

</span>

</td>


<td>

<button
    type="button"
    class="btn btn-outline btn-xs"
    onclick="viewDetail(
        <?= (int)$row['student_id'] ?>
    )"
>

Detail

</button>

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
   SEMESTER → CLASS FILTER
   ========================================================= */

function filterClasses() {

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

        if (
            semester === '0' ||
            option.dataset.semester === semester
        ) {

            option.hidden = false;

        } else {

            option.hidden = true;
        }

    });


    const selected =
        classSelect.options[
            classSelect.selectedIndex
        ];


    if (
        selected &&
        selected.dataset.semester &&
        semester !== '0' &&
        selected.dataset.semester !== semester
    ) {

        classSelect.value = '0';
    }
}


/* Run after page loads */

filterClasses();


/* =========================================================
   SEARCH
   ========================================================= */

function searchStudents() {

    const query =
        document.getElementById(
            'studentSearch'
        )
        .value
        .trim()
        .toLowerCase();


    const rows =
        document.querySelectorAll(
            '.student-report-row'
        );


    let visible = 0;


    rows.forEach(row => {

        const text =
            row.dataset.search || '';


        const show =
            text.includes(query);


        row.style.display =
            show ? '' : 'none';


        if (show) {
            visible++;
        }
    });


    document.getElementById(
        'visibleCount'
    ).textContent =
        visible;
}


/* =========================================================
   DETAIL
   ========================================================= */

function viewDetail(studentId) {

    const classId =
        <?= (int)$class_id ?>;

    const fromDate =
        <?= json_encode($from_date) ?>;

    const toDate =
        <?= json_encode($to_date) ?>;


    /*
     * We'll create this page next.
     */

    let url =
        'student_attendance_detail.php' +
        '?student_id=' +
        encodeURIComponent(studentId) +
        '&class_id=' +
        encodeURIComponent(classId);


    if (fromDate) {

        url +=
            '&from_date=' +
            encodeURIComponent(fromDate);
    }


    if (toDate) {

        url +=
            '&to_date=' +
            encodeURIComponent(toDate);
    }


    window.location.href =
        url;
}
/* =========================================================
   EXPORT EXCEL
   ========================================================= */

function exportExcel(event) {

    event.preventDefault();

    const params =
        new URLSearchParams(
            window.location.search
        );

    params.set(
        'export',
        'excel'
    );

    window.location.href =
        'attendance_report.php?' +
        params.toString();
}

function exportPDF(event) {

    event.preventDefault();

    const params =
        new URLSearchParams(
            window.location.search
        );

    params.delete('export');

    const url =
        'attendance_report_pdf.php?' +
        params.toString();

    window.open(
        url,
        '_blank'
    );
}
</script>


</body>
</html>