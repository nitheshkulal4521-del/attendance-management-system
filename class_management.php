<?php
session_start();
include("db_connect.php");

if (!isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login.php");
    exit();
}

/*
 teacher_id stored during login is currently the users.user_id.
 Find the corresponding teacher profile.
*/
$user_id = (int) $_SESSION['teacher_id'];

$stmt = $conn->prepare("
    SELECT teacher_id, teacher_name, department
    FROM teachers
    WHERE user_id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$teacher = $result->fetch_assoc();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_id = (int) $teacher['teacher_id'];
$selected_semester = isset($_GET['semester'])
    ? (int) $_GET['semester']
    : 0;

if ($selected_semester < 1 || $selected_semester > 8) {
    $selected_semester = 0;
}
$teacher_name = $teacher['teacher_name'];

$message = "";
$error = "";

/* ==========================
   FETCH TEACHER SUBJECTS
   ========================== */

$subject_stmt = $conn->prepare("
    SELECT
        subject_id,
        subject_name,
        semester
    FROM subjects
    WHERE teacher_id = ?
    ORDER BY semester, subject_name
");

$subject_stmt->bind_param("i", $teacher_id);
$subject_stmt->execute();

$subject_result = $subject_stmt->get_result();

$teacher_subjects = [];

while ($subject = $subject_result->fetch_assoc()) {
    $teacher_subjects[] = $subject;
}

$subject_stmt->close();

/* ==========================
   DELETE CLASS
   ========================== */

if (isset($_POST['delete_class'])) {

    $class_id = (int)($_POST['class_id'] ?? 0);

    if ($class_id > 0) {

        $delete = $conn->prepare("
            DELETE FROM classes
            WHERE class_id = ?
            AND teacher_id = ?
        ");

        $delete->bind_param("ii", $class_id, $teacher_id);

        if ($delete->execute()) {
            $message = "Class deleted successfully.";
        } else {
            $error = "Unable to delete class: " . $delete->error;
        }

        $delete->close();
    }
}
/* ==========================
   UPDATE CLASS
   ========================== */

/* ==========================
   UPDATE CLASS
   ========================== */

if (isset($_POST['update_class'])) {

    $class_id = (int)($_POST['class_id'] ?? 0);
    $department = trim($_POST['department'] ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $semester = (int)($_POST['semester'] ?? 0);
    $academic_year = trim($_POST['academic_year'] ?? '');
    $room_number = trim($_POST['room_number'] ?? '');

    if (
        $class_id <= 0 ||
        $department === '' ||
        $subject_name === '' ||
        $semester < 1 ||
        $semester > 8 ||
        $academic_year === ''
    ) {
        $error = "Please enter valid class details.";
    } else {

        // Make sure this class belongs to logged-in teacher
        $check = $conn->prepare("
            SELECT class_id
            FROM classes
            WHERE class_id = ?
              AND teacher_id = ?
        ");

        $check->bind_param("ii", $class_id, $teacher_id);
        $check->execute();

        $valid_class = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$valid_class) {

            $error = "Class not found.";

        } else {

            $conn->begin_transaction();

            try {

                /* Find existing subject */
                $subject_stmt = $conn->prepare("
                    SELECT subject_id
                    FROM subjects
                    WHERE teacher_id = ?
                      AND semester = ?
                      AND LOWER(TRIM(subject_name)) = LOWER(TRIM(?))
                    LIMIT 1
                ");

                $subject_stmt->bind_param(
                    "iis",
                    $teacher_id,
                    $semester,
                    $subject_name
                );

                $subject_stmt->execute();

                $subject =
                    $subject_stmt->get_result()->fetch_assoc();

                $subject_stmt->close();


                /* Create subject if it doesn't exist */
                if ($subject) {

                    $subject_id = (int)$subject['subject_id'];

                } else {

                    $new_subject = $conn->prepare("
                        INSERT INTO subjects
                            (teacher_id, subject_name, semester)
                        VALUES (?, ?, ?)
                    ");

                    $new_subject->bind_param(
                        "isi",
                        $teacher_id,
                        $subject_name,
                        $semester
                    );

                    $new_subject->execute();

                    $subject_id = $conn->insert_id;

                    $new_subject->close();
                }


                /*
                 * Keep class_name = subject name
                 * for compatibility with existing cards/search.
                 */
                $update = $conn->prepare("
                    UPDATE classes
                    SET
                        class_name = ?,
                        department = ?,
                        semester = ?,
                        academic_year = ?,
                        room_number = ?
                    WHERE class_id = ?
                      AND teacher_id = ?
                ");

                $update->bind_param(
                    "ssissii",
                    $subject_name,
                    $department,
                    $semester,
                    $academic_year,
                    $room_number,
                    $class_id,
                    $teacher_id
                );

                $update->execute();
                $update->close();


                /* Replace old class-subject relationship */
                $delete_link = $conn->prepare("
                    DELETE FROM class_subjects
                    WHERE class_id = ?
                ");

                $delete_link->bind_param("i", $class_id);
                $delete_link->execute();
                $delete_link->close();


                $new_link = $conn->prepare("
                    INSERT INTO class_subjects
                        (class_id, subject_id)
                    VALUES (?, ?)
                ");

                $new_link->bind_param(
                    "ii",
                    $class_id,
                    $subject_id
                );

                $new_link->execute();
                $new_link->close();

                $conn->commit();

                $message = "Class updated successfully.";

            } catch (Throwable $e) {

                $conn->rollback();

                $error = "Unable to update class: " . $e->getMessage();
            }
        }
    }
}
/* ==========================
   CREATE NEW CLASS
   ========================== */

if (isset($_POST['add_class'])) {

    $department = trim($_POST['department'] ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $semester = (int)($_POST['semester'] ?? 0);
    $academic_year = trim($_POST['academic_year'] ?? '');
    $room_number = trim($_POST['room_number'] ?? '');

    if (
        $department === '' ||
        $subject_name === '' ||
        $semester < 1 ||
        $semester > 8 ||
        $academic_year === ''
    ) {

        $error = "Please fill all required fields.";

    } else {

        $conn->begin_transaction();

        try {

            /* ==========================
               FIND EXISTING SUBJECT
               ========================== */

            $subject_check = $conn->prepare("
                SELECT subject_id
                FROM subjects
                WHERE teacher_id = ?
                  AND semester = ?
                  AND LOWER(TRIM(subject_name)) = LOWER(TRIM(?))
                LIMIT 1
            ");

            $subject_check->bind_param(
                "iis",
                $teacher_id,
                $semester,
                $subject_name
            );

            $subject_check->execute();

            $subject =
                $subject_check->get_result()->fetch_assoc();

            $subject_check->close();


            /* ==========================
               CREATE SUBJECT IF NEEDED
               ========================== */

            if ($subject) {

                $subject_id = (int)$subject['subject_id'];

            } else {

                $new_subject = $conn->prepare("
                    INSERT INTO subjects
                        (teacher_id, subject_name, semester)
                    VALUES (?, ?, ?)
                ");

                $new_subject->bind_param(
                    "isi",
                    $teacher_id,
                    $subject_name,
                    $semester
                );

                $new_subject->execute();

                $subject_id = $conn->insert_id;

                $new_subject->close();
            }


            /* ==========================
               CREATE CLASS
               ========================== */

            $insert = $conn->prepare("
                INSERT INTO classes
                (
                    teacher_id,
                    class_name,
                    department,
                    semester,
                    academic_year,
                    room_number
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $insert->bind_param(
                "ississ",
                $teacher_id,
                $subject_name,
                $department,
                $semester,
                $academic_year,
                $room_number
            );

            $insert->execute();

            $new_class_id = $conn->insert_id;

            $insert->close();


            /* ==========================
               CONNECT CLASS + SUBJECT
               ========================== */

            $link = $conn->prepare("
                INSERT INTO class_subjects
                    (class_id, subject_id)
                VALUES (?, ?)
            ");

            $link->bind_param(
                "ii",
                $new_class_id,
                $subject_id
            );

            $link->execute();
            $link->close();


            $conn->commit();

            $message = "Class created successfully.";

        } catch (Throwable $e) {

            $conn->rollback();

            $error = "Unable to create class: " . $e->getMessage();
        }
    }
}
/* ==========================
   FETCH TEACHER'S CLASSES
   WITH STUDENT COUNT
   ========================== */

if ($selected_semester > 0) {

    $class_stmt = $conn->prepare("
        SELECT
            c.class_id,
            c.class_name,
            c.department,
            c.semester,
            c.academic_year,
            c.room_number,

            COUNT(DISTINCT cs.student_id) AS student_count

        FROM classes c

        LEFT JOIN class_students cs
            ON cs.class_id = c.class_id

        WHERE c.teacher_id = ?
          AND c.semester = ?

        GROUP BY
            c.class_id,
            c.class_name,
            c.department,
            c.semester,
            c.academic_year,
            c.room_number

        ORDER BY c.class_id DESC
    ");

    $class_stmt->bind_param(
        "ii",
        $teacher_id,
        $selected_semester
    );

} else {

    $class_stmt = $conn->prepare("
        SELECT
            c.class_id,
            c.class_name,
            c.department,
            c.semester,
            c.academic_year,
            c.room_number,

            COUNT(DISTINCT cs.student_id) AS student_count

        FROM classes c

        LEFT JOIN class_students cs
            ON cs.class_id = c.class_id

        WHERE c.teacher_id = ?

        GROUP BY
            c.class_id,
            c.class_name,
            c.department,
            c.semester,
            c.academic_year,
            c.room_number

        ORDER BY c.class_id DESC
    ");

    $class_stmt->bind_param(
        "i",
        $teacher_id
    );
}



$class_stmt->execute();
$classes = $class_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Class Management – EduTrack ERP</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="shared.css">
<style>
.step-indicator{display:flex;align-items:center;gap:0;margin-bottom:28px}
.step{display:flex;align-items:center;gap:8px;font-size:0.8rem;font-weight:600}
.step-num{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;flex-shrink:0}
.step.done .step-num{background:var(--green);color:#fff}
.step.active .step-num{background:var(--blue);color:#fff}
.step.pending .step-num{background:var(--border);color:var(--muted)}
.step.active .step-label{color:var(--blue)}
.step.done .step-label{color:var(--green)}
.step.pending .step-label{color:var(--muted)}
.step-line{flex:1;height:2px;background:var(--border);margin:0 8px;min-width:30px}
.step-line.done{background:var(--green)}

.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.toolbar-left{display:flex;align-items:center;gap:10px}
.search-box{display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid var(--border);border-radius:9px;padding:8px 13px;transition:.2s}
.search-box:focus-within{border-color:var(--blue)}
.search-box svg{width:15px;height:15px;stroke:var(--muted);fill:none;stroke-width:2;flex-shrink:0}
.search-box input{border:none;outline:none;font-family:'DM Sans',sans-serif;font-size:0.85rem;color:var(--slate);background:transparent;width:180px}

.classes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
.class-card{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:20px;cursor:pointer;transition:.2s;position:relative}
.class-card:hover{border-color:var(--blue);box-shadow:0 6px 20px rgba(37,99,235,.1);transform:translateY(-2px)}
.class-card-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px}
.class-name{font-family:'DM Serif Display',serif;font-size:1.1rem;color:var(--slate)}
.class-dept{font-size:0.78rem;color:var(--muted);margin-top:2px}
.class-actions{display:flex;gap:6px}
.icon-btn{width:30px;height:30px;border-radius:7px;display:flex;align-items:center;justify-content:center;border:1px solid var(--border);background:#fff;cursor:pointer;transition:.15s}
.icon-btn:hover{background:var(--blue-bg);border-color:var(--blue)}
.icon-btn svg{width:13px;height:13px;stroke:var(--muted);fill:none;stroke-width:2}
.icon-btn:hover svg{stroke:var(--blue)}
.class-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px}
.cs-item{background:var(--bg);border-radius:8px;padding:9px 10px;text-align:center}
.cs-val{font-size:1rem;font-weight:700;color:var(--slate)}
.cs-key{font-size:0.68rem;color:var(--muted);margin-top:1px}
.class-footer{display:flex;align-items:center;justify-content:space-between}
.class-teacher{font-size:0.78rem;color:var(--muted);display:flex;align-items:center;gap:5px}
.class-teacher svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2}

/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(4px);z-index:100;display:none;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:20px;padding:32px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.15);animation:fadeIn .25s ease}
@keyframes fadeIn{from{opacity:0;transform:scale(.97)}to{opacity:1;transform:scale(1)}}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.modal-title{font-family:'DM Serif Display',serif;font-size:1.2rem;color:var(--slate)}
.modal-close{width:30px;height:30px;background:var(--bg);border:1px solid var(--border);border-radius:7px;display:flex;align-items:center;justify-content:center;cursor:pointer}
.modal-close svg{width:14px;height:14px;stroke:var(--muted);fill:none;stroke-width:2}
.modal-footer{display:flex;gap:10px;margin-top:20px}
.modal-footer .btn{flex:1;justify-content:center}

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
.sem-chip{display:inline-flex;align-items:center;gap:5px;background:var(--blue-bg);color:var(--blue);border:1px solid #bfdbfe;padding:4px 12px;border-radius:20px;font-size:0.78rem;font-weight:700;margin-bottom:20px}
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
       Manage Classes
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
</div><div id="sidebar-mount" data-active="classes"></div>

<div class="main">
  <div class="page-content">
    <div class="breadcrumb">
      <a href="teacher_dashboard.php">Dashboard</a>
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      <a href="semester_selection.php">Select Semester</a>
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      <span>Manage Classes</span>
    </div>
    <div class="page-header">
     <h1 class="page-title">Manage Departments</h1>
      <p class="page-sub">Add, edit, and view class sections for the selected semester</p>
    </div>

    <div class="step-indicator">
      <div class="step done"><div class="step-num">✓</div><div class="step-label">Semester</div></div>
      <div class="step-line done"></div>
      <div class="step active"><div class="step-num">2</div><div class="step-label">Class</div></div>
      <div class="step-line"></div>
      <div class="step pending"><div class="step-num">3</div><div class="step-label">Students</div></div>
    </div>

    <div class="sem-chip">
    <svg viewBox="0 0 24 24" width="12" height="12">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
    </svg>

    <?php if ($selected_semester >= 1 && $selected_semester <= 8): ?>
        Semester <?= $selected_semester ?>
    <?php else: ?>
        All Semesters
    <?php endif; ?>
</div>

    <div class="toolbar">
      <div class="toolbar-left">
        <div class="search-box">
          <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input type="text" placeholder="Search classes..." id="searchInput" oninput="filterClasses()">
        </div>
        <input
    type="text"
    class="form-control"
    style="width:190px"
    id="deptFilter"
    placeholder="Filter department..."
    oninput="filterClasses()"
>
      </div>
      <button class="btn btn-primary" onclick="openAddClassModal()">
        <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Class
      </button>
    </div>

    <div class="classes-grid" id="classGrid">

<?php if ($classes->num_rows > 0) { ?>

    <?php while ($class = $classes->fetch_assoc()) { ?>

        <div
            class="class-card"
            onclick="goToStudents(<?= (int)$class['class_id'] ?>)"
            data-dept="<?= htmlspecialchars($class['department']) ?>"
        >

            <div class="class-card-header">

                <div>
                    <div class="class-name">
                        <?= htmlspecialchars($class['class_name']) ?>
                    </div>

                    <div class="class-dept">
                        <?= htmlspecialchars($class['department']) ?>
                    </div>
                </div>

                <div class="class-actions"
                     onclick="event.stopPropagation()">

                    <div
    class="icon-btn"
    onclick='editClass(
        <?= (int)$class["class_id"] ?>,
        <?= json_encode($class["class_name"]) ?>,
        <?= json_encode($class["department"]) ?>,
        <?= (int)$class["semester"] ?>,
        <?= json_encode($class["academic_year"]) ?>,
        <?= json_encode($class["room_number"] ?? "") ?>
    )'
    title="Edit">

                        <svg viewBox="0 0 24 24">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>

                    </div>

                    <div
                        class="icon-btn"
                        onclick="confirmDelete(<?= (int)$class['class_id'] ?>)"
                        title="Delete">

                        <svg viewBox="0 0 24 24">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14H6L5 6"/>
                            <path d="M10 11v6"/>
                            <path d="M14 11v6"/>
                        </svg>

                    </div>

                </div>
            </div>


            <div class="class-stats">

                <div class="cs-item">

    <div class="cs-val">
        <?= (int)$class['student_count'] ?>
    </div>

    <div class="cs-key">
        Students
    </div>

</div>

                <div class="cs-item">
                    <div class="cs-val">0%</div>
                    <div class="cs-key">Avg Attend.</div>
                </div>

                <div class="cs-item">
                    <div class="cs-val">0</div>
                    <div class="cs-key">Classes</div>
                </div>

            </div>


            <div style="font-size:0.76rem;color:var(--muted);margin-bottom:12px;">

                Semester <?= (int)$class['semester'] ?>

                · <?= htmlspecialchars($class['academic_year']) ?>

                <?php if (!empty($class['room_number'])) { ?>
                    · Room <?= htmlspecialchars($class['room_number']) ?>
                <?php } ?>

            </div>


            <div class="class-footer">

                <div class="class-teacher">

                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    </svg>

                    <?= htmlspecialchars($teacher_name) ?>

                </div>

                <span class="badge badge-green">
                    <span class="badge-dot"></span>
                    Active
                </span>

            </div>

        </div>

    <?php } ?>

<?php } else { ?>

    <div class="card">

        <h3>No classes created yet</h3>

        <p style="color:var(--muted);margin-top:6px;">
            Click Add Class to create your first class.
        </p>

    </div>

<?php } ?>


<!-- Add Class Modal -->
</div> <!-- classGrid -->

  </div> <!-- page-content -->
</div> <!-- main -->


<!-- Add Class Modal -->
<div class="modal-overlay" id="addModal">

  <div class="modal">

    <div class="modal-header">
     <h2 class="modal-title" id="classModalTitle">Add New Class</h2>

      <div class="modal-close"
           onclick="document.getElementById('addModal').classList.remove('open')">
        <svg viewBox="0 0 24 24">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </div>
    </div>

    <form
    method="POST"
    id="classForm"
    action="class_management.php<?= $selected_semester > 0 ? '?semester=' . $selected_semester : '' ?>"
>
      <input type="hidden" name="class_id" id="editClassId">
      

      

      <div class="form-group">
        <label class="form-label">Department</label>
        <input
    type="text"
    name="department"
    id="classDepartment"
    class="form-control"
    placeholder="e.g. AI & DS"
    required
>
      </div>
    
    <div class="form-group">
    <label class="form-label">Subject Name</label>
    <input
        type="text"
        name="subject_name"
        id="classSubject"
        class="form-control"
        placeholder="e.g. Machine Learning"
        required
    >

</div>
      <div class="form-group">
        <label class="form-label" >Semester</label>
        <select
    name="semester"
    id="classSemester"
    class="form-control"
    required
>
          <option value="">Select Semester</option>
          <option value="1">Semester 1</option>
          <option value="2">Semester 2</option>
          <option value="3">Semester 3</option>
          <option value="4">Semester 4</option>
          <option value="5">Semester 5</option>
          <option value="6">Semester 6</option>
          <option value="7">Semester 7</option>
          <option value="8">Semester 8</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Academic Year</label>
        <input
    type="text"
    name="academic_year"
    id="classAcademicYear"
    class="form-control"
    value="2026-27"
    required
>
      </div>

      <div class="form-group">
        <label class="form-label">Room Number</label>
        <input
    type="text"
    name="room_number"
    id="classRoom"
    class="form-control"
    placeholder="e.g. CS-Lab-3"
>
      </div>

      <div class="modal-footer">

        <button
            type="button"
            class="btn btn-ghost"
            onclick="document.getElementById('addModal').classList.remove('open')">
          Cancel
        </button>

        <button
    type="submit"
    name="add_class"
    id="classSubmitBtn"
    class="btn btn-primary">
    Save Class
</button>

      </div>

    </form>

  </div>
</div>
<script src="shared.js"></script>

<script>

    

function goToStudents(classId) {
    window.location.href =
        `class_student_management.php?class_id=${classId}`;
}


function filterClasses() {

    // Converts:
    // "AI & DS" -> "aids"
    // "ai&ds"   -> "aids"
    // "AI-DS"   -> "aids"
    // "aids"    -> "aids"
    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/[^a-z0-9]/g, '');
    }

    const searchInput = normalize(
        document.getElementById('searchInput').value
    );

    const departmentInput = normalize(
        document.getElementById('deptFilter').value
    );

    document.querySelectorAll('.class-card').forEach(card => {

        const className = normalize(
            card.querySelector('.class-name')?.textContent
        );

        const department = normalize(
            card.dataset.dept
        );

        const matchesSearch =
            className.includes(searchInput) ||
            department.includes(searchInput);

        const matchesDepartment =
            department.includes(departmentInput);

        card.style.display =
            matchesSearch && matchesDepartment
                ? ''
                : 'none';
    });
}


function editClass(
    classId,
    className,
    department,
    semester,
    academicYear,
    roomNumber
) {

    document.getElementById('editClassId').value = classId;

    document.getElementById('classDepartment').value =
        department;

    // class_name currently contains subject name
    document.getElementById('classSubject').value =
        className;

    document.getElementById('classSemester').value =
        semester;

    document.getElementById('classAcademicYear').value =
        academicYear;

    document.getElementById('classRoom').value =
        roomNumber || '';

    document.getElementById('classModalTitle').textContent =
        'Edit Class';

    const button =
        document.getElementById('classSubmitBtn');

    button.textContent = 'Save Changes';
    button.name = 'update_class';

    document.getElementById('addModal')
        .classList.add('open');
}

function confirmDelete(classId) {

    if (!confirm("Are you sure you want to delete this class?")) {
        return;
    }

    const form = document.createElement("form");

    form.method = "POST";
    form.action = "class_management.php";

    const classInput = document.createElement("input");
    classInput.type = "hidden";
    classInput.name = "class_id";
    classInput.value = classId;

    const deleteInput = document.createElement("input");
    deleteInput.type = "hidden";
    deleteInput.name = "delete_class";
    deleteInput.value = "1";

    form.appendChild(classInput);
    form.appendChild(deleteInput);

    document.body.appendChild(form);

    form.submit();
}
function openAddClassModal() {

    document.getElementById('classForm').reset();

    document.getElementById('editClassId').value = '';

    document.getElementById('classModalTitle').textContent = 'Add New Class';

    const button = document.getElementById('classSubmitBtn');

    button.textContent = 'Save Class';
    button.name = 'add_class';

    document.getElementById('addModal').classList.add('open');
}
</script>
</body>
</html>
