<?php
session_start();
include("db_connect.php");

if (!isset($_SESSION['teacher_id'])) {
    header("Location: teacher_login.php");
    exit();
}

$user_id = (int)$_SESSION['teacher_id'];

$stmt = $conn->prepare("
    SELECT teacher_name
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

$teacher_name = $teacher['teacher_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Select Semester – EduTrack ERP</title>
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

.sem-grid{
    display:grid;
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:16px;
    width:100%;
    max-width:none;
}

.sem-btn{
    width:100%;
    min-width:0;
    background:#fff;
    border:2px solid var(--border);
    border-radius:16px;
    padding:28px 20px;
    text-align:center;
    cursor:pointer;
    transition:.25s;
    font-family:'DM Sans',sans-serif;
    position:relative;
    overflow:hidden;
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

.sem-btn::before{content:'';position:absolute;inset:0;background:var(--blue);transform:scaleY(0);transform-origin:bottom;transition:.2s;border-radius:14px;z-index:0}
.sem-btn:hover{border-color:var(--blue);transform:translateY(-3px);box-shadow:0 8px 24px rgba(37,99,235,.15)}
.sem-btn:hover::before{transform:scaleY(1)}
.sem-btn:hover .sem-num,.sem-btn:hover .sem-label{color:#fff;position:relative;z-index:1}
.sem-btn.active{border-color:var(--blue);background:var(--blue)}
.sem-btn.active .sem-num,.sem-btn.active .sem-label{color:#fff}
.sem-num{font-family:'DM Serif Display',serif;font-size:2rem;color:var(--slate);transition:.2s;position:relative;z-index:1}
.sem-label{font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-top:4px;transition:.2s;position:relative;z-index:1}
.sem-students{font-size:0.75rem;color:var(--muted);margin-top:8px;position:relative;z-index:1;transition:.2s}
.sem-btn:hover .sem-students,.sem-btn.active .sem-students{color:rgba(255,255,255,.8)}
.info-chip{display:inline-flex;align-items:center;gap:6px;background:var(--blue-bg);color:var(--blue);border:1px solid #bfdbfe;padding:6px 14px;border-radius:20px;font-size:0.8rem;font-weight:600;margin-bottom:20px}
.info-chip svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2}
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
@media(max-width:700px){
    .sem-grid{
        grid-template-columns:repeat(2,1fr);
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
        Add Student
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
    data-active="add-student">
</div>

<div class="main">
  <div class="page-content">
    <div class="breadcrumb">
      <a href="teacher_dashboard.php">Dashboard</a>
      <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
      <span>Select Semester</span>
    </div>
    <div class="page-header">
      <h1 class="page-title">Select Semester</h1>
      <p class="page-sub">Choose a semester to manage students and classes</p>
    </div>

    <!-- Step Indicator -->
    <div class="step-indicator">
      <div class="step active"><div class="step-num">1</div><div class="step-label">Semester</div></div>
      <div class="step-line"></div>
      <div class="step pending"><div class="step-num">2</div><div class="step-label">Class</div></div>
      <div class="step-line"></div>
      <div class="step pending"><div class="step-num">3</div><div class="step-label">Students</div></div>
    </div>

    <div class="info-chip">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Click a semester to continue
    </div>

    <div class="sem-grid">

  <div class="sem-btn" onclick="selectSem(1,this)">
    <div class="sem-num">I</div>
    <div class="sem-label">Semester</div>
  </div>

  <div class="sem-btn" onclick="selectSem(2,this)">
    <div class="sem-num">II</div>
    <div class="sem-label">Semester</div>
  </div>

  <div class="sem-btn" onclick="selectSem(3,this)">
    <div class="sem-num">III</div>
    <div class="sem-label">Semester</div>
  </div>

  <div class="sem-btn" onclick="selectSem(4,this)">
    <div class="sem-num">IV</div>
    <div class="sem-label">Semester</div>
  </div>

  <div class="sem-btn" onclick="selectSem(5,this)">
    <div class="sem-num">V</div>
    <div class="sem-label">Semester</div>
  </div>

  <div class="sem-btn" onclick="selectSem(6,this)">
    <div class="sem-num">VI</div>
    <div class="sem-label">Semester</div>
  </div>

  <div class="sem-btn" onclick="selectSem(7,this)">
    <div class="sem-num">VII</div>
    <div class="sem-label">Semester</div>
  </div>

  <div class="sem-btn" onclick="selectSem(8,this)">
    <div class="sem-num">VIII</div>
    <div class="sem-label">Semester</div>
  </div>

</div>

<script src="shared.js"></script>
<script>
function selectSem(n, el){

    document.querySelectorAll('.sem-btn').forEach(btn => {
        btn.classList.remove('active');
    });

    el.classList.add('active');

    setTimeout(() => {
        window.location.href = `class_management.php?semester=${n}`;
    }, 300);
}
</script>
</body>
</html>
