<?php

session_start();

include("db_connect.php");


/* =========================================================
   TEACHER LOGIN CHECK
   ========================================================= */

if (!isset($_SESSION['teacher_id'])) {

    header("Location: teacher_login.php");
    exit();
}


/*
 * teacher_id in session currently stores users.user_id
 */

$user_id =
    (int)$_SESSION['teacher_id'];


/* =========================================================
   GET TEACHER PROFILE
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


$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$result =
    $stmt->get_result();

$teacher =
    $result->fetch_assoc();

$stmt->close();


if (!$teacher) {

    die(
        "Teacher profile not found."
    );
}


$teacher_id =
    (int)$teacher['teacher_id'];

$teacher_name =
    $teacher['teacher_name'];

$department =
    $teacher['department'] ?? '';



/* =========================================================
   FILTERS
   ========================================================= */

$semester =
    (int)($_GET["semester"] ?? 0);

$class_id =
    (int)($_GET["class_id"] ?? 0);

$date =
    trim($_GET["date"] ?? "");

$search =
    trim($_GET["search"] ?? "");


/* =========================================================
   FILTERS
   ========================================================= */

$semester = (int)($_GET["semester"] ?? 0);
$class_id = (int)($_GET["class_id"] ?? 0);
$date = trim($_GET["date"] ?? "");
$search = trim($_GET["search"] ?? "");


/* =========================================================
   TEACHER CLASSES
   ========================================================= */

$stmt = $conn->prepare("
    SELECT
        class_id,
        class_name,
        semester
    FROM classes
    WHERE teacher_id = ?
    ORDER BY semester, class_name
");

$stmt->bind_param("i", $teacher_id);
$stmt->execute();

$class_result = $stmt->get_result();

$teacher_classes = [];

while ($row = $class_result->fetch_assoc()) {
    $teacher_classes[] = $row;
}

$stmt->close();


/* =========================================================
   GET REASONS
   ========================================================= */

$sql = "
    SELECT
        ar.reason_id,
        ar.student_id,
        ar.session_id,
        ar.reason,
        ar.proof_file,
        ar.status,

        s.student_name,
        s.roll_no,

        cs.class_date,

        c.class_id,
        c.class_name,
        c.semester,

        sub.subject_name

    FROM absence_reasons ar

    INNER JOIN students s
        ON s.student_id = ar.student_id

    INNER JOIN class_sessions cs
        ON cs.session_id = ar.session_id

    INNER JOIN classes c
        ON c.class_id = cs.class_id

    INNER JOIN subjects sub
        ON sub.subject_id = cs.subject_id

    WHERE c.teacher_id = ?
";


$params = [$teacher_id];
$types = "i";


if ($semester > 0) {

    $sql .= " AND c.semester = ?";

    $params[] = $semester;
    $types .= "i";
}


if ($class_id > 0) {

    $sql .= " AND c.class_id = ?";

    $params[] = $class_id;
    $types .= "i";
}


if ($date !== "") {

    $sql .= " AND cs.class_date = ?";

    $params[] = $date;
    $types .= "s";
}


if ($search !== "") {

    $sql .= "
        AND (
            s.student_name LIKE ?
            OR s.roll_no LIKE ?
        )
    ";

    $search_value = "%" . $search . "%";

    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "ss";
}


$sql .= "
    ORDER BY
        CASE ar.status
            WHEN 'Pending' THEN 1
            WHEN 'Approved' THEN 2
            WHEN 'Rejected' THEN 3
        END,
        cs.class_date DESC,
        ar.reason_id DESC
";


$stmt = $conn->prepare($sql);

$stmt->bind_param(
    $types,
    ...$params
);

$stmt->execute();

$result = $stmt->get_result();

$reasons = [];

while ($row = $result->fetch_assoc()) {
    $reasons[] = $row;
}

$stmt->close();

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
Reasons & Certificates – EduTrack ERP
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

.reason-filter {
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    padding:22px;
    margin-bottom:24px;
}

.filter-row {
    display:flex;
    flex-wrap:wrap;
    gap:16px;
    align-items:flex-end;
}

.filter-col {
    display:flex;
    flex-direction:column;
    gap:6px;
    min-width:170px;
}

.reason-text {
    max-width:260px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    color:var(--muted);
}

.action-group {
    display:flex;
    gap:6px;
    flex-wrap:wrap;
}

.modal-overlay {
    position:fixed;
    inset:0;
    background:rgba(15,23,42,.45);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:200;
    backdrop-filter:blur(5px);
}

.modal-overlay.open {
    display:flex;
}

.modal {
    width: 100%;
    max-width: 550px;

    background: #fff;
    border-radius: 18px;
    padding: 30px;

    box-shadow: 0 25px 60px rgba(0,0,0,.18);

    position: relative;

    max-height: 90vh;
    overflow-y: auto;
}

.modal-title {
    font-family:'DM Serif Display',serif;
    font-size:1.3rem;
    margin-bottom:20px;
}

.detail-row {
    display:flex;
    justify-content:space-between;
    gap:20px;
    padding:10px 0;
    border-bottom:1px solid var(--border);
}

.detail-label {
    color:var(--muted);
    font-weight:600;
}

.detail-value {
    color:var(--slate);
    font-weight:600;
    text-align:right;
}

.reason-box {
    margin-top: 18px !important;
    padding: 16px 18px !important;

    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;

    display: block !important;

    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
}

.reason-label {
    display: block;
    margin: 0 0 6px 0 !important;
    padding: 0 !important;

    font-size: 14px;
    font-weight: 600;
    color: #64748b;
}

.reason-value {
    display: block !important;

    margin: 0 !important;
    padding: 0 !important;

    height: auto !important;
    min-height: 0 !important;

    font-size: 16px;
    font-weight: 500;
    line-height: 1.5;
    color: #0f172a;

    white-space: pre-wrap;
}

.reason-box strong {
    display: block;
    margin-bottom: 8px;
    font-size: 0.9rem;
    color: var(--muted);
}

.reason-box .reason-content {
    font-size: 1rem;
    color: var(--slate);
    font-weight: 500;
    white-space: pre-wrap;
}

.modal-footer {
    display: flex;
    gap: 10px;
    margin-top: 24px;
}

.modal-close {
    position: absolute;
    top: 16px;
    right: 18px;

    width: 36px;
    height: 36px;

    border: none;
    border-radius: 50%;

    background: #f1f5f9;
    color: #475569;

    font-size: 22px;
    line-height: 1;

    cursor: pointer;

    display: flex;
    align-items: center;
    justify-content: center;
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
.modal-close:hover {
    background: #e2e8f0;
    color: #0f172a;
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
        Reasons & Certificates
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
    data-active="reasons"
></div>


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
Reasons & Certificates
</span>

</div>


<div class="page-header">

<h1 class="page-title">
Reasons & Certificates
</h1>

<p class="page-sub">
Review student absence reasons and uploaded certificates.
</p>

</div>


<!-- FILTER -->

<form
    method="GET"
    class="reason-filter"
>

<div class="filter-row">


<div class="filter-col">

<label class="form-label">
Semester
</label>

<select
    class="form-control"
    name="semester"
>

<option value="0">
All Semesters
</option>

<?php for ($i = 1; $i <= 8; $i++): ?>

<option
    value="<?= $i ?>"
    <?= $semester === $i ? "selected" : "" ?>
>
    Semester <?= $i ?>
</option>

<?php endfor; ?>

</select>

</div>



<div class="filter-col">

<label class="form-label">
Class
</label>

<select
    class="form-control"
    name="class_id"
>

<option value="0">
All Classes
</option>

<?php foreach ($teacher_classes as $class): ?>

<option
    value="<?= (int)$class["class_id"] ?>"
    <?= $class_id === (int)$class["class_id"] ? "selected" : "" ?>
>

<?= htmlspecialchars($class["class_name"]) ?>

</option>

<?php endforeach; ?>

</select>

</div>



<div class="filter-col">

<label class="form-label">
Date
</label>

<input
    type="date"
    class="form-control"
    name="date"
    value="<?= htmlspecialchars($date) ?>"
>

</div>



<div class="filter-col">

<label class="form-label">
Search Student
</label>

<input
    type="text"
    class="form-control"
    name="search"
    placeholder="Name or USN"
    value="<?= htmlspecialchars($search) ?>"
>

</div>



<button
    type="submit"
    class="btn btn-primary"
>

<svg viewBox="0 0 24 24">
<circle cx="11" cy="11" r="8"/>
<line
    x1="21"
    y1="21"
    x2="16.65"
    y2="16.65"
/>
</svg>

Search

</button>


<a
    href="view_reasons.php"
    class="btn btn-ghost"
>
Clear
</a>


</div>

</form>


<!-- TABLE -->

<div
    class="card"
    style="padding:0"
>

<div
    class="table-wrap"
    style="border:none"
>

<table>

<thead>

<tr>

<th>#</th>
<th>Student</th>
<th>Roll No</th>
<th>Class</th>
<th>Subject</th>
<th>Date</th>
<th>Reason</th>
<th>Certificate</th>
<th>Status</th>
<th>Action</th>

</tr>

</thead>


<tbody>


<?php if (count($reasons) === 0): ?>

<tr>

<td
    colspan="10"
    class="empty-row"
>
No absence reasons found.
</td>

</tr>


<?php else: ?>


<?php foreach ($reasons as $index => $reason): ?>

<tr>

<td>
<?= $index + 1 ?>
</td>


<td style="font-weight:700;">

<?= htmlspecialchars(
    $reason["student_name"]
) ?>

</td>


<td style="font-family:monospace;">

<?= htmlspecialchars(
    $reason["roll_no"]
) ?>

</td>


<td>

<?= htmlspecialchars(
    $reason["class_name"]
) ?>

</td>


<td>

<?= htmlspecialchars(
    $reason["subject_name"]
) ?>

</td>


<td>

<?= date(
    "d M Y",
    strtotime($reason["class_date"])
) ?>

</td>


<td>

<div class="reason-text">

<?= htmlspecialchars(
    $reason["reason"]
) ?>

</div>

</td>


<td>

<?php if (!empty($reason["proof_file"])): ?>

<a
    class="btn btn-outline btn-xs"
    href="uploads/proofs/<?= rawurlencode($reason["proof_file"]) ?>"
    target="_blank"
>
View
</a>

<?php else: ?>

<span style="color:var(--muted)">
Not Uploaded
</span>

<?php endif; ?>

</td>


<td>

<?php if ($reason["status"] === "Pending"): ?>

<span class="badge badge-yellow">
<span class="badge-dot"></span>
Pending
</span>

<?php elseif ($reason["status"] === "Approved"): ?>

<span class="badge badge-green">
<span class="badge-dot"></span>
Approved
</span>

<?php else: ?>

<span class="badge badge-red">
<span class="badge-dot"></span>
Rejected
</span>

<?php endif; ?>

</td>


<td>

<button
    type="button"
    class="btn btn-primary btn-xs"
    onclick='viewReason(<?= json_encode($reason) ?>)'
>
View
</button>

</td>

</tr>


<?php endforeach; ?>


<?php endif; ?>


</tbody>

</table>

</div>

</div>


</div>
</div>


<!-- DETAILS MODAL -->

<div
    class="modal-overlay"
    id="reasonModal"
>

<div class="modal">

<button
    type="button"
    class="modal-close"
    onclick="closeModal()"
    aria-label="Close"
>
    &times;
</button>

<h2 class="modal-title">
    Reason Details
</h2>


<div id="modalContent"></div>


<div
    class="modal-footer"
    id="modalActions"
>

<button
    type="button"
    class="btn btn-success"
    id="approveBtn"
>
Approve
</button>


<button
    type="button"
    class="btn btn-danger"
    id="rejectBtn"
>
Reject
</button>


<button
    type="button"
    class="btn btn-ghost"
    onclick="closeModal()"
>
Close
</button>

</div>

</div>

</div>


<script src="shared.js"></script>


<script>

let selectedReasonId = null;


function escapeHtml(value) {

    const div =
        document.createElement("div");

    div.textContent =
        value ?? "";

    return div.innerHTML;
}


function viewReason(reason) {

    selectedReasonId =
        reason.reason_id;


    const proofLink =
        reason.proof_file

        ? `
            <a
                href="uploads/proofs/${encodeURIComponent(reason.proof_file)}"
                target="_blank"
                class="btn btn-outline btn-xs"
            >
                View Certificate
            </a>
          `

        : "Not Uploaded";


    document.getElementById(
        "modalContent"
    ).innerHTML = `

        <div class="detail-row">

            <div class="detail-label">
                Student
            </div>

            <div class="detail-value">
                ${escapeHtml(reason.student_name)}
            </div>

        </div>


        <div class="detail-row">

            <div class="detail-label">
                Roll No
            </div>

            <div class="detail-value">
                ${escapeHtml(reason.roll_no)}
            </div>

        </div>


        <div class="detail-row">

            <div class="detail-label">
                Class
            </div>

            <div class="detail-value">
                ${escapeHtml(reason.class_name)}
            </div>

        </div>


        <div class="detail-row">

            <div class="detail-label">
                Subject
            </div>

            <div class="detail-value">
                ${escapeHtml(reason.subject_name)}
            </div>

        </div>


        <div class="detail-row">

            <div class="detail-label">
                Date
            </div>

            <div class="detail-value">
                ${escapeHtml(reason.class_date)}
            </div>

        </div>


        <div class="detail-row">

            <div class="detail-label">
                Certificate
            </div>

            <div class="detail-value">
                ${proofLink}
            </div>

        </div>


       <div class="reason-box">
    <span class="reason-label">Reason</span>
    <p class="reason-value">${escapeHtml(reason.reason)}</p>
</div>
    `;


    const approveBtn =
        document.getElementById(
            "approveBtn"
        );

    const rejectBtn =
        document.getElementById(
            "rejectBtn"
        );


    if (reason.status === "Pending") {

        approveBtn.style.display =
            "inline-flex";

        rejectBtn.style.display =
            "inline-flex";

    } else {

        approveBtn.style.display =
            "none";

        rejectBtn.style.display =
            "none";
    }


    document.getElementById(
        "reasonModal"
    ).classList.add("open");
}


function closeModal() {

    document.getElementById(
        "reasonModal"
    ).classList.remove("open");
}


document.getElementById(
    "approveBtn"
).addEventListener(
    "click",
    function() {

        updateReasonStatus(
            "Approved"
        );
    }
);


document.getElementById(
    "rejectBtn"
).addEventListener(
    "click",
    function() {

        updateReasonStatus(
            "Rejected"
        );
    }
);


async function updateReasonStatus(status) {

    if (!selectedReasonId) {
        return;
    }


    try {

        const response =
            await fetch(
                "update_reason_status.php",
                {
                    method:"POST",

                    headers:{
                        "Content-Type":
                            "application/json"
                    },

                    body:
                        JSON.stringify({
                            reason_id:
                                selectedReasonId,

                            status:
                                status
                        })
                }
            );


        const data =
            await response.json();


        if (!data.success) {

            alert(
                data.message ||
                "Unable to update status."
            );

            return;
        }


        window.location.reload();


    } catch (error) {

        console.error(error);

        alert(
            "Unable to connect to the server."
        );
    }
}


window.onclick =
function(event) {

    const modal =
        document.getElementById(
            "reasonModal"
        );

    if (event.target === modal) {
        closeModal();
    }
};

</script>


</body>
</html>