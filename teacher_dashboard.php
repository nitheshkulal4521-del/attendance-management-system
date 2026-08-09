<?php
session_start();
date_default_timezone_set('Asia/Kolkata');
include("db_connect.php");

if (!isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login.php");
    exit();
}

/*
 $_SESSION['teacher_id'] currently stores users.user_id
*/
$user_id = $_SESSION['teacher_id'];

/* Get teacher profile */
$stmt = $conn->prepare("
SELECT

    teacher_id,

    teacher_name,

    employee_id,

    department,

    email

FROM teachers

WHERE user_id=?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$teacher = $result->fetch_assoc();

$employee_id = $teacher['employee_id'];
$email       = $teacher['email'];

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_id   = $teacher['teacher_id'];
$teacher_name = $teacher['teacher_name'];
$department   = $teacher['department'] ?? '';

/* Get total classes for this teacher */
$class_stmt = $conn->prepare("
    SELECT COUNT(*) AS total_classes
    FROM classes
    WHERE teacher_id = ?
");

$class_stmt->bind_param("i", $teacher_id);
$class_stmt->execute();

$class_result = $class_stmt->get_result();
$class_data = $class_result->fetch_assoc();

$total_classes = $class_data['total_classes'] ?? 0;

$class_stmt->close();


/* =========================================================
   TOTAL UNIQUE STUDENTS
   ========================================================= */

/*
 * A student may belong to multiple subjects/classes.
 * COUNT DISTINCT prevents the same student from being
 * counted multiple times.
 */

$student_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT cs.student_id) AS total_students

    FROM class_students cs

    INNER JOIN classes c
        ON c.class_id = cs.class_id

    WHERE c.teacher_id = ?
");

$student_stmt->bind_param(
    "i",
    $teacher_id
);

$student_stmt->execute();

$student_data =
    $student_stmt
        ->get_result()
        ->fetch_assoc();

$total_students =
    (int)($student_data['total_students'] ?? 0);

$student_stmt->close();



/* =========================================================
   ATTENDANCE STATISTICS
   ========================================================= */

/*
 * Effective Present =
 *
 * Actual Present
 * +
 * Granted Grace
 *
 * This uses the same logic as Attendance Report,
 * Student Detail and Shortage List.
 */

$attendance_stmt = $conn->prepare("
    SELECT

        COUNT(
            DISTINCT CONCAT(
                cs.student_id,
                '-',
                sess.session_id
            )
        ) AS total_records,

        COUNT(
            DISTINCT CASE

                WHEN
                    a.status = 'Present'
                    OR ga.status = 'Granted'

                THEN CONCAT(
                    cs.student_id,
                    '-',
                    sess.session_id
                )

            END
        ) AS effective_present

    FROM class_students cs

    INNER JOIN classes c
        ON c.class_id = cs.class_id

    INNER JOIN class_sessions sess
        ON sess.class_id = c.class_id

    LEFT JOIN attendance a
        ON a.student_id = cs.student_id
       AND a.session_id = sess.session_id

    LEFT JOIN grace_attendance ga
        ON ga.attendance_id = a.attendance_id
       AND ga.status = 'Granted'

    WHERE c.teacher_id = ?
");

$attendance_stmt->bind_param(
    "i",
    $teacher_id
);

$attendance_stmt->execute();

$attendance_data =
    $attendance_stmt
        ->get_result()
        ->fetch_assoc();

$total_attendance_records =
    (int)($attendance_data['total_records'] ?? 0);

$effective_present =
    (int)($attendance_data['effective_present'] ?? 0);

$attendance_stmt->close();


/* Average Attendance */

if ($total_attendance_records > 0) {

    $average_attendance = round(
        ($effective_present /
         $total_attendance_records) * 100,
        1
    );

} else {

    $average_attendance = 0;
}



/* =========================================================
   SHORTAGE COUNT
   ========================================================= */

/*
 * Calculate each student separately for each class/subject.
 *
 * Therefore:
 *
 * Student A - Data Science = 80%
 * Student A - Python       = 60%
 *
 * Only the Python enrollment is in shortage.
 */

$shortage_stmt = $conn->prepare("
    SELECT COUNT(*) AS shortage_count

    FROM
    (
        SELECT
            cs.student_id,
            cs.class_id,

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
            ) AS effective_present

        FROM class_students cs

        INNER JOIN classes c
            ON c.class_id = cs.class_id

        LEFT JOIN class_sessions sess
            ON sess.class_id = c.class_id

        LEFT JOIN attendance a
            ON a.student_id = cs.student_id
           AND a.session_id = sess.session_id

        LEFT JOIN grace_attendance ga
            ON ga.attendance_id = a.attendance_id
           AND ga.status = 'Granted'

        WHERE c.teacher_id = ?

        GROUP BY
            cs.student_id,
            cs.class_id

        HAVING
            total_classes > 0

            AND
            (
                effective_present /
                total_classes
            ) * 100 < 75

    ) shortage
");

$shortage_stmt->bind_param(
    "i",
    $teacher_id
);

$shortage_stmt->execute();

$shortage_data =
    $shortage_stmt
        ->get_result()
        ->fetch_assoc();

$shortage_count =
    (int)($shortage_data['shortage_count'] ?? 0);

$shortage_stmt->close();


/* =========================================================
   RECENT ACTIVITY
   ========================================================= */

$activity_stmt = $conn->prepare("
    SELECT
        al.activity_id,
        al.action_type,
        al.description,
        al.created_at,

        c.class_name,
        c.semester

    FROM activity_logs al

    LEFT JOIN classes c
        ON c.class_id = al.class_id

    WHERE al.teacher_id = ?

    ORDER BY al.created_at DESC,
             al.activity_id DESC

    LIMIT 5
");

$activity_stmt->bind_param(
    "i",
    $teacher_id
);

$activity_stmt->execute();

$activity_result =
    $activity_stmt->get_result();

$recent_activities = [];

while ($row = $activity_result->fetch_assoc()) {
    $recent_activities[] = $row;
}

$activity_stmt->close();

?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard – EduTrack ERP</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="shared.css">
<style>
.welcome-card{background:linear-gradient(135deg,var(--blue) 0%,var(--blue-dark) 100%);border-radius:16px;padding:28px 32px;color:#fff;display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;position:relative;overflow:hidden}
.welcome-card::after{content:'';position:absolute;right:-20px;top:-20px;width:160px;height:160px;background:rgba(255,255,255,.07);border-radius:50%}
.welcome-card::before{content:'';position:absolute;right:60px;bottom:-30px;width:100px;height:100px;background:rgba(255,255,255,.05);border-radius:50%}
.wc-title{
    font-family:'DM Serif Display',serif;
    font-size:1.3rem;   /* or 1.25rem */
    font-weight:700;
    margin-bottom:4px;
    line-height:1.3;
}
.teacher-name{
    text-transform:uppercase;
}
.wc-sub{font-size:0.85rem;opacity:.85}
.wc-date{font-size:0.8rem;opacity:.7;margin-top:8px}
.wc-right{text-align:right;z-index:1}
.wc-right .day-num{font-family:'DM Serif Display',serif;font-size:3rem;line-height:1;opacity:.9}
.wc-right .day-label{font-size:0.8rem;opacity:.7}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card .stat-icon.blue{background:var(--blue-bg);color:var(--blue)}
.stat-card .stat-icon.green{background:var(--green-bg);color:var(--green)}
.stat-card .stat-icon.yellow{background:var(--yellow-bg);color:var(--yellow)}
.stat-card .stat-icon.red{background:var(--red-bg);color:var(--red)}
.modules-title{font-family:'DM Serif Display',serif;font-size:1.1rem;color:var(--slate);margin-bottom:16px}
.modules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.module-card{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:22px 20px;cursor:pointer;transition:.2s;text-decoration:none;display:block;position:relative;overflow:hidden}
.module-card:hover{border-color:var(--blue);transform:translateY(-3px);box-shadow:0 8px 24px rgba(37,99,235,.12)}
.module-card:hover .module-icon{background:var(--blue);color:#fff}
.module-icon{width:44px;height:44px;border-radius:11px;background:var(--blue-bg);color:var(--blue);display:flex;align-items:center;justify-content:center;margin-bottom:14px;transition:.2s}
.module-icon svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.8}
.module-name{font-size:0.9rem;font-weight:700;color:var(--slate);margin-bottom:4px}
.module-desc{font-size:0.77rem;color:var(--muted);line-height:1.4}
.module-arrow{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted);opacity:0;transition:.2s}
.module-card:hover .module-arrow{opacity:1;right:12px}
.module-arrow svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2}
.module-badge{position:absolute;top:14px;right:14px;background:var(--red);color:#fff;font-size:0.65rem;font-weight:700;padding:2px 7px;border-radius:10px}
.recent-section{margin-top:28px}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.profile-popup{
    position:absolute;
    top:65px;
    right:0;
    width:360px;
    background:#fff;
    border-radius:18px;
    box-shadow:0 10px 35px rgba(0,0,0,.15);

    display:none;      /* ADD THIS */

    z-index:999;
}

.profile-item{
    display:grid;
    grid-template-columns:130px 1fr;
    align-items:center;
    padding:14px 0;
    border-bottom:1px solid #e5e7eb;
}

.profile-item strong{
    font-size:15px;
    font-weight:600;
}

.profile-item span{
    text-align:right;
    font-size:15px;
}
.profile-popup.show{
    display:block;
    
}

@keyframes popup{
    from{
        opacity:0;
        transform:translateY(-10px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}


.profile-details{
    padding:0 32px;
}



.detail-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:16px 0;
    border-bottom:1px solid #eef2f7;
}

.detail-row span{
    color:#64748b;
}

.detail-row strong{
    font-weight:600;
}

.profile-action{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:16px 20px;
    cursor:pointer;
    transition:.2s;
}

.profile-action:hover{
    background:#eff6ff;
    color:#2563eb;
}


.btn-logout{
    width:140px;          /* Same width */
    height:44px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    border-radius:10px;
}


.profile-heading{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:10px;
    font-size:18px;
    font-weight:700;
    color:#1f2937;
    margin:20px 0 15px;
}

.profile-details{
    padding:0 20px;
}

/* Avatar in top bar */
.profile-btn .profile-avatar{
    width:38px;
    height:38px;
    border-radius:50%;
    background:#2563eb;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:16px;
    font-weight:700;
    margin:0;
}

/* Avatar inside popup */

.password-modal{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.45);
    display:none;
    justify-content:center;
    align-items:center;
    z-index:10000;
}

.password-modal.show{
    display:flex;
}

.password-box{
    width:420px;
    background:#fff;
    border-radius:16px;
    padding:24px;
    box-shadow:0 15px 40px rgba(0,0,0,.2);
}

.password-input{
    width:100%;
    padding:12px;
    border:1px solid #ddd;
    border-radius:8px;
    margin-top:6px;
}

.password-actions{
    display:flex;
    justify-content:flex-end;
    gap:12px;
    margin-top:20px;
}

.cancel-btn,
.update-btn{
    padding:10px 18px;
    border:none;
    border-radius:8px;
    cursor:pointer;
}

.cancel-btn{
    background:#e5e7eb;
}

.update-btn{
    background:#2563eb;
    color:#fff;
}

#toast{
    position:fixed;
    top:20px;
    right:20px;
    padding:14px 18px;
    border-radius:10px;
    display:none;
    color:#fff;
    z-index:10001;
}

#toast.show{
    display:block;
}

#toast.success{
    background:#16a34a;
}

#toast.error{
    background:#dc2626;
}

.profile-btn{
    width:140px;
    height:44px;
    display:flex;
    align-items:center;
    gap:12px;
    padding:0 16px;
    border:1px solid #bfdbfe;
    border-radius:10px;
    background:#eff6ff;
    cursor:pointer;
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
.profile-btn:hover{

    background:#dbeafe;
}




.profile-popup.show{
    display:block;
}


.change-password-btn{
   
    width:calc(100% - 40px);
    margin:20px;
    height:46px;
    border-radius:12px;
    border:none;
    background:#2563eb;
    color:#fff;
    font-size:15px;
    font-weight:600;
    cursor:pointer;
}
.section-title{font-family:'DM Serif Display',serif;font-size:1.05rem;color:var(--slate)}
@media(max-width:900px){.stats-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.welcome-card{flex-direction:column;gap:12px;text-align:center}.wc-right{display:none}}
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
        Dashboard
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
    data-active="dashboard"
    data-shortage-count="<?= $shortage_count ?>"
></div>

<div class="main">
  <div class="page-content">
    <!-- Welcome -->
    <div class="welcome-card">
      <div>
        <?php
$hour = date("H");

if ($hour >= 5 && $hour < 12) {
    $greeting = "Good Morning";
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = "Good Afternoon";
} elseif ($hour >= 17 && $hour < 21) {
    $greeting = "Good Evening";
} else {
    $greeting = "Good Night";
}
?>

<h1 class="wc-title">
    <?= $greeting ?>,
    <span class="teacher-name">
        <?= strtoupper(htmlspecialchars($teacher_name)) ?>
    </span>
</h1>

<div class="wc-sub">
    <?php echo htmlspecialchars($teacher['department']); ?> Department
</div>
        <div class="wc-date" id="todayDate"></div>
      </div>
      <div class="wc-right">
        <div class="day-num" id="dayNum"></div>
        <div class="day-label" id="dayLabel"></div>
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-value"><?= $total_classes ?></div>
<div class="stat-label">Total Classes</div>
        <div class="stat-icon blue"><svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></div>
      </div>
      <div class="stat-card">
        <div class="stat-value">
    <?= $total_students ?>
</div>

<div class="stat-label">
    Total Students
</div>
        <div class="stat-icon green"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
      </div>
      <div class="stat-card">
        <div class="stat-value">
    <?= $shortage_count ?>
</div>

<div class="stat-label">
    Shortage Alerts
</div>
        <div class="stat-icon red"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
      </div>
      <div class="stat-card">
       <div class="stat-value">
    <?= number_format(
        $average_attendance,
        1
    ) ?>%
</div>

<div class="stat-label">
    Avg Attendance
</div>
        <div class="stat-icon yellow"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
      </div>
    </div>

    <!-- Modules -->
    <div class="modules-title">Quick Access – Modules</div>
    <div class="modules-grid">
      <a href="semester_selection.php" class="module-card">
        <div class="module-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
        <div class="module-name">Add Student</div>
        <div class="module-desc">Enroll students to specific classes and semesters</div>
        <div class="module-arrow"><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></div>
      </a>
      <a href="class_management.php" class="module-card">
        <div class="module-icon"><svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></div>
        <div class="module-name">Manage Classes</div>
        <div class="module-desc">Create, edit and organise class sections</div>
        <div class="module-arrow"><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></div>
      </a>
      <a href="mark_attendance.php" class="module-card">
        <div class="module-icon"><svg viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
        <div class="module-name">Mark Attendance</div>
        <div class="module-desc">Record daily attendance for class sessions</div>
        <div class="module-arrow"><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></div>
      </a>
      <a href="attendance_report.php" class="module-card">
        <div class="module-icon"><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
        <div class="module-name">Attendance Report</div>
        <div class="module-desc">View and export detailed attendance analytics</div>
        <div class="module-arrow"><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></div>
      </a>
      <a href="shortage_list.php" class="module-card">

    <?php if ($shortage_count > 0): ?>
        <span class="module-badge">
            <?= $shortage_count ?>
        </span>
    <?php endif; ?>

    <div class="module-icon">
        <svg viewBox="0 0 24 24">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
    </div>

    <div class="module-name">Shortage List</div>
    <div class="module-desc">
        Students with attendance below 75% threshold
    </div>

    <div class="module-arrow">
        <svg viewBox="0 0 24 24">
            <line x1="5" y1="12" x2="19" y2="12"/>
            <polyline points="12 5 19 12 12 19"/>
        </svg>
    </div>

</a>
      <a href="grace_management.php" class="module-card">
        <div class="module-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <div class="module-name">Grace Management</div>
        <div class="module-desc">Grant attendance grace to eligible students</div>
        <div class="module-arrow"><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></div>
      </a>
      <a href="view_reasons.php" class="module-card">
        <div class="module-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
        <div class="module-name">Reasons & Certificates</div>
        <div class="module-desc">Review student-submitted absence justifications</div>
        <div class="module-arrow"><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></div>
      </a>
    </div>

    <!-- Recent Activity -->
    <div class="recent-section">
      <div class="section-header">
        <div class="section-title">Recent Activity</div>
<a href="activity_history.php" class="btn btn-ghost btn-sm">
    View All
</a>      </div>
      <div class="card" style="padding:0">
        <div class="table-wrap" style="border:none;border-radius:14px">
          <table>
            <thead><tr><th>Action</th><th>Class</th><th>Date</th><th>Status</th></tr></thead>
            <tbody>

<?php if (!empty($recent_activities)): ?>

    <?php foreach ($recent_activities as $activity): ?>

        <tr>

            <td>
                <strong>
                    <?= htmlspecialchars(
                        $activity['action_type']
                    ) ?>
                </strong>

                <div style="
                    font-size:.72rem;
                    color:var(--muted);
                    margin-top:3px;
                ">
                    <?= htmlspecialchars(
                        $activity['description']
                    ) ?>
                </div>
            </td>


            <td>

                <?php if (!empty($activity['class_name'])): ?>

                    <?= htmlspecialchars(
                        $activity['class_name']
                    ) ?>

                    <?php if (!empty($activity['semester'])): ?>

                        <span style="color:var(--muted)">
                            (Sem <?= (int)$activity['semester'] ?>)
                        </span>

                    <?php endif; ?>

                <?php else: ?>

                    <span style="color:var(--muted)">
                        —
                    </span>

                <?php endif; ?>

            </td>


            <td>
                <?= date(
                    'd M Y, h:i A',
                    strtotime(
                        $activity['created_at']
                    )
                ) ?>
            </td>


            <td>

                <?php
                $action =
                    $activity['action_type'];

                if ($action === 'Attendance Marked') {

                    $badge_class = 'badge-green';
                    $status_text = 'Saved';

                } elseif ($action === 'Student Added') {

                    $badge_class = 'badge-blue';
                    $status_text = 'Success';

                } elseif ($action === 'Grace Granted') {

                    $badge_class = 'badge-green';
                    $status_text = 'Granted';

                } elseif ($action === 'Grace Denied') {

                    $badge_class = 'badge-red';
                    $status_text = 'Denied';

                } else {

                    $badge_class = 'badge-blue';
                    $status_text = 'Done';
                }
                ?>

                <span class="badge <?= $badge_class ?>">
                    <span class="badge-dot"></span>
                    <?= $status_text ?>
                </span>

            </td>

        </tr>

    <?php endforeach; ?>


<?php else: ?>

    <tr>

        <td
            colspan="4"
            style="
                text-align:center;
                padding:28px;
                color:var(--muted);
            "
        >
            No recent activity yet.
        </td>

    </tr>

<?php endif; ?>

</tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="shared.js"></script>


<script>

function openPasswordModal(){

    document
        .getElementById("passwordModal")
        .classList
        .add("show");
}

function closePasswordModal(){

    document
        .getElementById("passwordModal")
        .classList
        .remove("show");
}
function showToast(message, type = "success") {

    const toast = document.getElementById("toast");

    toast.innerHTML =
        (type === "success" ? "✅ " :
         type === "error" ? "❌ " : "⚠️ ")
        + message;

    toast.classList.remove("success", "error", "warning", "show");

    toast.classList.add(type);
    toast.classList.add("show");

    setTimeout(() => {

        toast.classList.remove("show");

    }, 3000);

}
async function changePassword() {

    const currentPassword = document.getElementById("currentPassword").value.trim();
    const newPassword = document.getElementById("newPassword").value.trim();
    const confirmPassword = document.getElementById("confirmPassword").value.trim();

    if (!currentPassword || !newPassword || !confirmPassword) {
        showToast("Please fill all fields.", "error");
        return;
    }
    console.log({
   user_id: <?= $user_id ?>,
    role: "teacher",
    current_password: currentPassword,
    new_password: newPassword,
    confirm_password: confirmPassword
});

    try {

        const response = await fetch("api/change_password.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                user_id: <?= $user_id ?>,
                role: "teacher",
                current_password: currentPassword,
                new_password: newPassword,
                confirm_password: confirmPassword
            })
        });

        const result = await response.json();
         console.log(result);
        if (result.success) {

    showToast(result.message, "success");

    setTimeout(() => {

        closePasswordModal();

        document.getElementById("profilePopup").classList.remove("show");

        document.getElementById("currentPassword").value = "";
        document.getElementById("newPassword").value = "";
        document.getElementById("confirmPassword").value = "";

    }, 500);

}else {

            showToast(result.message, "error");

        }

    } catch (err) {

        console.error(err);
        showToast("Server error.", "error");

    }
}
function toggleProfile(event){

    event.stopPropagation();

    document
        .getElementById("profilePopup")
        .classList
        .toggle("show");

}
document.addEventListener("click", function (event) {

    const popup = document.getElementById("profilePopup");
    const button = document.querySelector(".profile-btn");

    if (
        !popup.contains(event.target) &&
        !button.contains(event.target)
    ) {
        popup.classList.remove("show");
    }

});
</script>
<script>
const d = new Date();
document.getElementById('todayDate').textContent = d.toLocaleDateString('en-IN',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
document.getElementById('dayNum').textContent = d.getDate();
document.getElementById('dayLabel').textContent = d.toLocaleDateString('en-IN',{month:'short',year:'numeric'});
</script>

<!-- =======================
Teacher Profile Popup
======================= -->
<div id="profilePopup" class="profile-popup">

    <h3 class="profile-heading">
        👤 Teacher Information
    </h3>

    <div class="profile-details">

        <div class="profile-item">
            <strong>Name</strong>
            <span><?= htmlspecialchars($teacher_name) ?></span>
        </div>

        <div class="profile-item">
            <strong>Employee ID</strong>
            <span><?= htmlspecialchars($employee_id) ?></span>
        </div>

        <div class="profile-item">
            <strong>Department</strong>
            <span><?= htmlspecialchars($department) ?></span>
        </div>

        <div class="profile-item">
            <strong>Email</strong>
            <span><?= htmlspecialchars($email) ?></span>
        </div>

    </div>

    <button
    class="change-password-btn"
    onclick="openPasswordModal()"
>
    🔑 Change Password
</button>

</div>

<!-- Change Password Modal -->

<div class="password-modal" id="passwordModal">

    <div class="password-box">

        <h2>🔑 Change Password</h2>

        <div class="form-group">
            <label>Current Password</label>
            <input
                type="password"
                id="currentPassword"
                class="password-input"
            >
        </div>

        <div class="form-group">
            <label>New Password</label>
            <input
                type="password"
                id="newPassword"
                class="password-input"
            >
        </div>

        <div class="form-group">
            <label>Confirm Password</label>
            <input
                type="password"
                id="confirmPassword"
                class="password-input"
            >
        </div>

        <div class="password-actions">

            <button
                class="cancel-btn"
                onclick="closePasswordModal()"
            >
                Cancel
            </button>

            <button
                class="update-btn"
                onclick="changePassword()"
            >
                Update Password
            </button>

        </div>

    </div>

</div>
<div id="toast"></div>


</body>
</html>
