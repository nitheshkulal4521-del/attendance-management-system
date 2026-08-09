<?php
session_start();
include("db_connect.php");

if (!isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login.php");
    exit();
}

/* Logged-in user's ID */
$user_id = (int) $_SESSION['teacher_id'];

/* Find actual teacher profile */
$teacher_stmt = $conn->prepare("
    SELECT teacher_id, teacher_name, department
    FROM teachers
    WHERE user_id = ?
");

$teacher_stmt->bind_param("i", $user_id);
$teacher_stmt->execute();

$teacher_result = $teacher_stmt->get_result();
$teacher = $teacher_result->fetch_assoc();

if (!$teacher) {
    die("Teacher profile not found.");
}

$teacher_id = (int) $teacher['teacher_id'];

/* Get class ID from URL */

/* Get class ID from URL */
if (!isset($_GET['class_id']) || !is_numeric($_GET['class_id'])) {
    die("Invalid class selected.");
}

$class_id = (int) $_GET['class_id'];


/* Get selected class */
$stmt = $conn->prepare("
    SELECT *
    FROM classes
    WHERE class_id = ? AND teacher_id = ?
");

$stmt->bind_param("ii", $class_id, $teacher_id);
$stmt->execute();

$class_result = $stmt->get_result();

if ($class_result->num_rows !== 1) {
    die("Class not found.");
}

$class = $class_result->fetch_assoc();

/* ==========================
   CLASS AVERAGE ATTENDANCE
   ========================== */

$avg_stmt = $conn->prepare("
    SELECT
        COUNT(a.attendance_id) AS total_records,
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present_records
    FROM attendance a
    INNER JOIN class_sessions sess
        ON sess.session_id = a.session_id
    WHERE sess.class_id = ?
");

$avg_stmt->bind_param("i", $class_id);
$avg_stmt->execute();

$avg_result = $avg_stmt->get_result();
$avg_data = $avg_result->fetch_assoc();

$total_attendance_records = (int)($avg_data['total_records'] ?? 0);
$present_attendance_records = (int)($avg_data['present_records'] ?? 0);

if ($total_attendance_records > 0) {

    $avg_attendance = round(
        ($present_attendance_records / $total_attendance_records) * 100,
        1
    );

} else {

    $avg_attendance = 0;
}

$avg_stmt->close();

/* ==========================
   EXPORT STUDENTS CSV
   ========================== */

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    $export_stmt = $conn->prepare("
    SELECT
        s.roll_no,
        s.student_name,
        s.department,
        s.semester
    FROM class_students cs
    INNER JOIN students s
        ON s.student_id = cs.student_id
    WHERE cs.class_id = ?
    ORDER BY s.roll_no
");

    $export_stmt->bind_param("i", $class_id);
    $export_stmt->execute();

    $export_result = $export_stmt->get_result();

    $filename = "students_class_" . $class_id . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // Excel-friendly UTF-8
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Roll Number',
        'Student Name',
        'Department',
        'Semester'
    ]);

    while ($row = $export_result->fetch_assoc()) {

        fputcsv($output, [
            $row['roll_no'],
            $row['student_name'],
            $row['department'],
            $row['semester']
        ]);
    }

    fclose($output);
    exit();
}
/* ==========================
   REMOVE STUDENT
   ========================== */

/* ==========================
   REMOVE STUDENT FROM CLASS
   ========================== */

if (isset($_POST['remove_student'])) {

    $student_id = (int)($_POST['student_id'] ?? 0);

    if ($student_id > 0) {

        $delete = $conn->prepare("
            DELETE FROM class_students
            WHERE student_id = ?
              AND class_id = ?
        ");

        $delete->bind_param(
            "ii",
            $student_id,
            $class_id
        );

        if ($delete->execute()) {

            header(
                "Location: class_student_management.php?class_id=" .
                $class_id
            );
            exit();

        } else {

            $error = "Unable to remove student from this class.";
        }

        $delete->close();
    }
}

/* ==========================
   EDIT STUDENT
   ========================== */

/* ==========================
   EDIT STUDENT
   ========================== */

if (isset($_POST['edit_student'])) {

    $student_id   = (int)($_POST['student_id'] ?? 0);
    $student_name = trim($_POST['student_name'] ?? '');
    $roll_no      = trim($_POST['roll_no'] ?? '');

    if (
        $student_id <= 0 ||
        $student_name === '' ||
        $roll_no === ''
    ) {

        $error = "Student name and roll number are required.";

    } else {

        /* Make sure student belongs to this class */

        $check = $conn->prepare("
            SELECT 1
            FROM class_students
            WHERE class_id = ?
              AND student_id = ?
            LIMIT 1
        ");

        $check->bind_param(
            "ii",
            $class_id,
            $student_id
        );

        $check->execute();

        $belongs =
            $check->get_result()->num_rows > 0;

        $check->close();

        if (!$belongs) {

            $error = "Student does not belong to this class.";

        } else {

            $update = $conn->prepare("
                UPDATE students
                SET
                    student_name = ?,
                    roll_no = ?
                WHERE student_id = ?
            ");

            $update->bind_param(
                "ssi",
                $student_name,
                $roll_no,
                $student_id
            );

            try {

                $update->execute();
                $update->close();

                header(
                    "Location: class_student_management.php?class_id=" .
                    $class_id
                );

                exit();

            } catch (mysqli_sql_exception $e) {

                if ($e->getCode() == 1062) {

                    $error =
                        "This roll number belongs to another student.";

                } else {

                    $error =
                        "Unable to update student.";
                }
            }
        }
    }
}
/* Add student */
/* ==========================
   ADD / ENROLL STUDENT
   ========================== */

if (isset($_POST['add_student'])) {

    $student_name =
        trim($_POST['student_name'] ?? '');

    $roll_no =
        trim($_POST['roll_no'] ?? '');

    if (
        $student_name === '' ||
        $roll_no === ''
    ) {

        $error =
            "Student name and roll number are required.";

    } else {

        $semester =
            (int)$class['semester'];

        $department =
            $class['department'];

        /*
         * Check whether this USN already exists.
         */

        $check_student = $conn->prepare("
            SELECT
                student_id,
                student_name,
                roll_no
            FROM students
            WHERE roll_no = ?
            LIMIT 1
        ");

        $check_student->bind_param(
            "s",
            $roll_no
        );

        $check_student->execute();

        $existing_student =
            $check_student
                ->get_result()
                ->fetch_assoc();

        $check_student->close();


        /* =============================================
           STUDENT ALREADY EXISTS
           ============================================= */

        if ($existing_student) {

            $student_id =
                (int)$existing_student['student_id'];


            /*
             * Check if already enrolled
             * in THIS class.
             */

            $check_class = $conn->prepare("
                SELECT 1
                FROM class_students
                WHERE class_id = ?
                  AND student_id = ?
                LIMIT 1
            ");

            $check_class->bind_param(
                "ii",
                $class_id,
                $student_id
            );

            $check_class->execute();

            $already_added =
                $check_class
                    ->get_result()
                    ->num_rows > 0;

            $check_class->close();


            if ($already_added) {

                $error =
                    "This student is already added to this class.";

            } else {

                /*
                 * Existing student.
                 * Only create class relationship.
                 */

                $link = $conn->prepare("
                    INSERT INTO class_students
                        (class_id, student_id)
                    VALUES (?, ?)
                ");

                $link->bind_param(
                    "ii",
                    $class_id,
                    $student_id
                );

                $link->execute();

                $link->close();


                header(
                    "Location: class_student_management.php?class_id=" .
                    $class_id
                );

                exit();
            }


        /* =============================================
           NEW STUDENT
           ============================================= */

        } else {

            try {

                $conn->begin_transaction();


                /*
                 * Temporary compatibility:
                 * keep class_id because existing pages
                 * still use it for now.
                 */

                $insert = $conn->prepare("
    INSERT INTO students
    (
        roll_no,
        student_name,
        semester,
        department
    )
    VALUES (?, ?, ?, ?)
");

$insert->bind_param(
    "ssis",
    $roll_no,
    $student_name,
    $semester,
    $department
);

                $insert->execute();

                $student_id =
                    $conn->insert_id;

                $insert->close();


                /*
                 * Enroll student in class.
                 */

                $link = $conn->prepare("
                    INSERT INTO class_students
                        (class_id, student_id)
                    VALUES (?, ?)
                ");

                $link->bind_param(
                    "ii",
                    $class_id,
                    $student_id
                );

                $link->execute();

                $link->close();


                $conn->commit();


                header(
                    "Location: class_student_management.php?class_id=" .
                    $class_id
                );

                exit();


            } catch (mysqli_sql_exception $e) {

                $conn->rollback();

                if ($e->getCode() == 1062) {

                    $error =
                        "This roll number already exists.";

                } else {

                    $error =
                        "Unable to add student.";
                }
            }
        }
    }
}


/* ==========================
   GET STUDENTS ENROLLED IN THIS CLASS
   ========================== */

$student_stmt = $conn->prepare("
    SELECT
        s.student_id,
        s.roll_no,
        s.student_name,
        s.semester,
        s.department

    FROM class_students cs

    INNER JOIN students s
        ON s.student_id = cs.student_id

    WHERE cs.class_id = ?

    ORDER BY s.roll_no
");

$student_stmt->bind_param(
    "i",
    $class_id
);

$student_stmt->execute();

$students = $student_stmt->get_result();

$total_students = $students->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Management – EduTrack ERP</title>
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

.layout{display:grid;grid-template-columns:340px 1fr;gap:20px;align-items:start}
.class-info-card{background:linear-gradient(135deg,var(--blue) 0%,var(--blue-dark) 100%);border-radius:14px;padding:24px;color:#fff;margin-bottom:16px}
.ci-label{font-size:0.72rem;opacity:.75;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
.ci-val{font-family:'DM Serif Display',serif;font-size:1.5rem}
.ci-sub{font-size:0.82rem;opacity:.8;margin-top:4px}
.ci-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,.2)}
.ci-stat{background:rgba(255,255,255,.1);border-radius:8px;padding:10px 12px}
.ci-stat-val{font-size:1.1rem;font-weight:700}
.ci-stat-key{font-size:0.7rem;opacity:.75;margin-top:2px}

.form-card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:24px}
.form-title{font-size:0.9rem;font-weight:700;color:var(--slate);margin-bottom:18px;display:flex;align-items:center;gap:8px}
.form-title svg{width:16px;height:16px;stroke:var(--blue);fill:none;stroke-width:2}

.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px}
.search-box{display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid var(--border);border-radius:9px;padding:8px 13px}
.search-box:focus-within{border-color:var(--blue)}
.search-box svg{width:14px;height:14px;stroke:var(--muted);fill:none;stroke-width:2}
.search-box input{border:none;outline:none;font-family:'DM Sans',sans-serif;font-size:0.85rem;color:var(--slate);background:transparent;width:160px}
.roll-cell{font-family:'DM Mono',monospace;font-size:0.82rem;color:var(--blue);font-weight:600}
.action-btns{display:flex;gap:6px}
.no-students{text-align:center;padding:40px;color:var(--muted)}
.no-students svg{width:40px;height:40px;stroke:var(--border);fill:none;stroke-width:1.5;margin-bottom:12px}
.no-students p{font-size:0.9rem}
@media(max-width:800px){.layout{grid-template-columns:1fr}}

.modal-overlay{
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.45);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:1000;
    padding:20px;
}

.modal-overlay.open{
    display:flex;
}

.modal{
    background:#fff;
    width:100%;
    max-width:430px;
    border-radius:16px;
    padding:24px;
    box-shadow:0 20px 60px rgba(0,0,0,.18);
}

.modal-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;
}

.modal-title{
    font-family:'DM Serif Display',serif;
    font-size:1.3rem;
    color:var(--slate);
}

.modal-close{
    width:32px;
    height:32px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px solid var(--border);
    border-radius:8px;
    cursor:pointer;
    font-size:20px;
    color:var(--muted);
}

.modal-close:hover{
    background:var(--red-bg);
    color:var(--red);
}
</style>
</head>
<body>
<div id="topbar-mount" data-page="Student Management"></div>
<div id="sidebar-mount" data-active="add-student"></div>

<div class="main">
  <div class="page-content">
    <div class="breadcrumb">
      <a href="teacher_dashboard.php">Dashboard</a>
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      <a href="semester_selection.php">Semester</a>
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      <a href="class_management.php">Classes</a>
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      <span><?= htmlspecialchars($class['class_name']) ?></span>
    </div>
    <div class="page-header">
      <h1 class="page-title">
    <?= htmlspecialchars($class['class_name']) ?>
</h1>

<p class="page-sub">
    Semester <?= htmlspecialchars($class['semester']) ?>
    ·
    <?= htmlspecialchars($class['department']) ?>
</p>
    </div>

    <div class="step-indicator">
      <div class="step done"><div class="step-num">✓</div><div class="step-label">Semester</div></div>
      <div class="step-line done"></div>
      <div class="step done"><div class="step-num">✓</div><div class="step-label">Class</div></div>
      <div class="step-line done"></div>
      <div class="step active"><div class="step-num">3</div><div class="step-label">Students</div></div>
    </div>

    <div class="layout">
      <!-- Left: Info + Form -->
      <div>
        <div class="class-info-card">
          <div class="ci-label">Current Class</div>
          <div class="ci-val">
    <?= htmlspecialchars($class['class_name']) ?>
</div>

<div class="ci-sub">
    Semester <?= htmlspecialchars($class['semester']) ?>
    · <?= htmlspecialchars($class['department']) ?>
</div>
          <div class="ci-stats">
            <div class="ci-stat"><div class="ci-stat-val"><?= $total_students ?></div><div class="ci-stat-key">Enrolled Students</div></div>
            <div class="ci-stat">
    <div class="ci-stat-val">
        <?= $avg_attendance ?>%
    </div>

    <div class="ci-stat-key">
        Avg Attendance
    </div>
</div>
          </div>
        </div>
        <div class="form-card">
          

    <div class="form-title">
        <svg viewBox="0 0 24 24">
            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="8.5" cy="7" r="4"/>
            <line x1="20" y1="8" x2="20" y2="14"/>
            <line x1="23" y1="11" x2="17" y2="11"/>
        </svg>
        Add New Student
    </div>

    <?php if (!empty($error)) { ?>
        <div class="alert alert-warning">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php } ?>

    <form method="POST">

        <div class="form-group">
            <label class="form-label">Student Name</label>

            <input
                type="text"
                class="form-control"
                name="student_name"
                placeholder="Full name of student"
                required
            >
        </div>

        <div class="form-group">
            <label class="form-label">Roll Number</label>

            <input
                type="text"
                class="form-control"
                name="roll_no"
                placeholder="e.g. 4SN23AI001"
                required
            >
        </div>

        <div class="form-group">
            <label class="form-label">Department</label>

            <input
                type="text"
                class="form-control"
                value="<?= htmlspecialchars($class['department']) ?>"
                readonly
            >
        </div>

        <div class="form-group">
            <label class="form-label">Semester</label>

            <input
                type="text"
                class="form-control"
                value="Semester <?= htmlspecialchars($class['semester']) ?>"
                readonly
            >
        </div>

        <div style="display:flex;gap:10px;margin-top:4px">

            <button
                type="reset"
                class="btn btn-ghost"
                style="flex:1;justify-content:center">
                Clear
            </button>

            <button
                type="submit"
                name="add_student"
                class="btn btn-primary"
                style="flex:2;justify-content:center">

                Add Student

            </button>

        </div>

    </form>
</div>
</div>

      <!-- Right: Student Table -->
      <div>
        <div class="toolbar">
          <div class="search-box">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" placeholder="Search students..." oninput="filterStudents(this.value)">
          </div>
          <div style="display:flex;gap:8px">
            <a
    href="class_student_management.php?class_id=<?= $class_id ?>&export=csv"
    class="btn btn-ghost btn-sm"
>
    <svg viewBox="0 0 24 24">
        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
        <polyline points="7 10 12 15 17 10"/>
        <line x1="12" y1="15" x2="12" y2="3"/>
    </svg>

    Export
</a>
          </div>
        </div>
        <div class="card" style="padding:0">
          <div class="table-wrap" style="border:none;border-radius:14px">
            <table id="studentTable">
              <thead><tr><th>#</th><th>Roll No.</th><th>Student Name</th><th>Department</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="studentBody">
                

<?php if ($total_students > 0) { ?>

    <?php $i = 1; ?>

    <?php while ($student = $students->fetch_assoc()) { ?>

        <tr>
            <td><?= $i++ ?></td>

            <td class="roll-cell">
                <?= htmlspecialchars($student['roll_no']) ?>
            </td>

            <td>
                <?= htmlspecialchars($student['student_name']) ?>
            </td>

            <td>
                <?= htmlspecialchars($student['department']) ?>
            </td>

            <td>
                <span class="badge badge-green">
                    <span class="badge-dot"></span>
                    Active
                </span>
            </td>

            <td>
                <div class="action-btns">

                    <button
    type="button"
    class="btn btn-outline btn-xs"
    onclick='openEditModal(
        <?= (int)$student["student_id"] ?>,
        <?= json_encode($student["student_name"]) ?>,
        <?= json_encode($student["roll_no"]) ?>
    )'>
    Edit
</button>
                    <form method="POST"
      onsubmit="return confirm('Are you sure you want to remove this student?');">

    <input
        type="hidden"
        name="student_id"
        value="<?= (int)$student['student_id'] ?>"
    >

    <button
        type="submit"
        name="remove_student"
        class="btn btn-danger btn-xs">
        Remove
    </button>

</form>

                </div>
            </td>

        </tr>

    <?php } ?>

<?php } else { ?>

    <tr>
        <td colspan="6">
            <div class="no-students">
                <p>No students added to this class yet.</p>
            </div>
        </td>
    </tr>

<?php } ?>

</tbody>
              
            </table>
          </div>
        </div>
        <div style="margin-top:12px;font-size:0.78rem;color:var(--muted);text-align:right">Showing <?= $total_students ?> students</div>
      </div>
    </div>
  </div>
</div>
<div class="modal-overlay" id="editModal">

    <div class="modal">

        <div class="modal-header">

            <h2 class="modal-title">Edit Student</h2>

            <div class="modal-close" onclick="closeEditModal()">
                ×
            </div>

        </div>

        <form method="POST">

            <input
                type="hidden"
                name="student_id"
                id="editStudentId"
            >

            <div class="form-group">
                <label class="form-label">Student Name</label>

                <input
                    type="text"
                    name="student_name"
                    id="editStudentName"
                    class="form-control"
                    required
                >
            </div>

            <div class="form-group">
                <label class="form-label">Roll Number</label>

                <input
                    type="text"
                    name="roll_no"
                    id="editRollNo"
                    class="form-control"
                    required
                >
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px">

                <button
                    type="button"
                    class="btn btn-ghost"
                    onclick="closeEditModal()">
                    Cancel
                </button>

                <button
                    type="submit"
                    name="edit_student"
                    class="btn btn-primary">
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>

<script src="shared.js?v=4"></script>

<script>
function openEditModal(id, name, rollNo) {

    document.getElementById('editStudentId').value = id;
    document.getElementById('editStudentName').value = name;
    document.getElementById('editRollNo').value = rollNo;

    document.getElementById('editModal').classList.add('open');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
}
function filterStudents(value) {

    const search = value.toLowerCase().trim();

    const rows = document.querySelectorAll(
        '#studentBody tr'
    );

    rows.forEach(row => {

        const rollNo = row.cells[1]?.textContent.toLowerCase() || '';
        const studentName = row.cells[2]?.textContent.toLowerCase() || '';
        const department = row.cells[3]?.textContent.toLowerCase() || '';

        const match =
            rollNo.includes(search) ||
            studentName.includes(search) ||
            department.includes(search);

        row.style.display = match ? '' : 'none';
    });
}
</script>

</body>
</html>