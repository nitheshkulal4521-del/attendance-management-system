<?php
session_start();
include("db_connect.php");

$error = "";
$success = "";
$create_error = "";

if (isset($_POST['login'])) {
$username = mysqli_real_escape_string($conn, $_POST['username']);
$password = mysqli_real_escape_string($conn, $_POST['password']);
    $sql = "SELECT * FROM users WHERE username=? AND role='teacher'";

$stmt = mysqli_prepare($conn, $sql);

mysqli_stmt_bind_param($stmt, "s", $username);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) == 1) {

    $row = mysqli_fetch_assoc($result);

    if (password_verify($password, $row['password'])) {

        $_SESSION['teacher_id'] = $row['user_id'];
        $_SESSION['username']   = $row['username'];

        header("Location: teacher_dashboard.php");
        exit();

    } else {

        $error = "Invalid Username or Password";

    }

} else {

    $error = "Invalid Username or Password";

}

}
if (isset($_POST['create_account'])) {

    $teacher_name = mysqli_real_escape_string($conn, $_POST['teacher_name']);
    $employee_id = mysqli_real_escape_string($conn, $_POST['employee_id']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);
   $password = password_hash(
    $_POST['create_password'],
    PASSWORD_DEFAULT
);

    // Check whether Employee ID already exists
    $check = mysqli_query(
        $conn,
        "SELECT user_id FROM users WHERE username='$employee_id'"
    );

    if (mysqli_num_rows($check) > 0) {

        $create_error = "Employee ID already exists.";

    } else {

        // Insert login account
        $user_insert = mysqli_query(
            $conn,
            "INSERT INTO users (username, password, role)
             VALUES ('$employee_id', '$password', 'teacher')"
        );

        if (!$user_insert) {
            die("Users Table Error: " . mysqli_error($conn));
        }

        // Get user_id created above
        $user_id = mysqli_insert_id($conn);

        // Insert teacher information
        $teacher_insert = mysqli_query(
    $conn,
    "INSERT INTO teachers
    (user_id, teacher_name, employee_id, email, department)
    VALUES
    ('$user_id', '$teacher_name', '$employee_id', '$email', '$department')"
);

if (!$teacher_insert) {
    die("Teachers Table Error: " . mysqli_error($conn));
}

$success = "Account created successfully. You can now sign in.";
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Login – EduTrack ERP</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
:root {
  --blue:#2563eb; --blue-dark:#1d4ed8; --blue-bg:#eff6ff;
  --slate:#0f172a; --muted:#64748b; --border:#e2e8f0;
  --bg:#f8fafc; --green:#16a34a; --green-bg:#f0fdf4;
  --red:#dc2626; --red-bg:#fef2f2;
  --yellow:#d97706; --yellow-bg:#fffbeb;
}
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:105vh;background:var(--bg);font-family:'DM Sans',sans-serif;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.bg-pattern{position:fixed;inset:0;background-image:radial-gradient(circle at 20% 20%, #dbeafe 0%, transparent 50%),radial-gradient(circle at 80% 80%, #e0f2fe 0%, transparent 50%);pointer-events:none}
.bg-grid{position:fixed;inset:0;background-image:linear-gradient(var(--border) 1px,transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);background-size:40px 40px;opacity:0.5;pointer-events:none}
.brand-strip{position:fixed;top:0;left:0;right:0;background:#fff;border-bottom:1px solid var(--border);padding:14px 32px;display:flex;align-items:center;gap:10px;z-index:10}
.brand-logo{width:32px;height:32px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center}
.brand-logo svg{width:18px;height:18px;fill:#fff}
.brand-name{font-family:'DM Serif Display',serif;font-size:1.1rem;color:var(--slate)}
.brand-tag{font-size:0.7rem;color:var(--muted);margin-left:4px}
.login-wrap{width:100%;max-width:420px;padding:20px;z-index:1}
.login-card{background:#fff;border:1px solid var(--border);border-radius:20px;padding:40px;box-shadow:0 4px 6px -1px rgba(0,0,0,.04),0 20px 40px -8px rgba(37,99,235,.08);animation:slideUp .4s ease}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.login-header{text-align:center;margin-bottom:32px}
.login-icon{width:56px;height:56px;background:var(--blue-bg);border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
.login-icon svg{width:28px;height:28px;stroke:var(--blue);fill:none;stroke-width:1.8}
.login-title{font-family:'DM Serif Display',serif;font-size:1.6rem;color:var(--slate);margin-bottom:6px}
.login-sub{font-size:0.85rem;color:var(--muted)}
.form-group{margin-bottom:18px}
label{display:block;font-size:0.8rem;font-weight:600;color:var(--slate);margin-bottom:7px;letter-spacing:.3px;text-transform:uppercase}
input[type=text],
input[type=email],
input[type=password] {
    width:100%;
    padding:11px 14px;
    border:1.5px solid var(--border);
    border-radius:10px;
    font-family:'DM Sans',sans-serif;
    font-size:0.92rem;
    color:var(--slate);
    background:#fafafa;
    transition:all .2s;
    outline:none;
}
input:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.input-icon-wrap{position:relative}
.input-icon{position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--muted)}
.input-icon svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.8}
.forgot{text-align:right;margin-top:-10px;margin-bottom:18px}
.forgot a{font-size:0.8rem;color:var(--blue);text-decoration:none;font-weight:500}
.forgot a:hover{color:var(--blue-dark)}
.btn-login{width:100%;padding:12px;background:var(--blue);color:#fff;border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:0.95rem;font-weight:600;cursor:pointer;transition:all .2s;letter-spacing:.2px}
.btn-login:hover{background:var(--blue-dark);transform:translateY(-1px);box-shadow:0 6px 20px rgba(37,99,235,.3)}
.btn-login:active{transform:translateY(0)}
.divider{display:flex;align-items:center;gap:12px;margin:22px 0;color:var(--muted);font-size:0.82rem}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--border)}
.btn-create{width:100%;padding:11px;background:#fff;color:var(--blue);border:1.5px solid var(--blue);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:0.9rem;font-weight:600;cursor:pointer;transition:all .2s}
.btn-create:hover{background:var(--blue-bg)}
.error-msg{background:var(--red-bg);color:var(--red);border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:0.83rem;margin-bottom:16px;display:none}
/* Modal */
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.4);backdrop-filter:blur(4px);z-index:100;display:none;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal{background:#fff;border-radius:20px;padding:36px;width:100%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.15);animation:slideUp .3s ease}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.modal-title{font-family:'DM Serif Display',serif;font-size:1.3rem;color:var(--slate)}
.modal-close{width:32px;height:32px;background:var(--bg);border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:.2s}
.modal-close:hover{background:var(--red-bg);border-color:#fecaca}
.modal-close svg{width:15px;height:15px;stroke:var(--muted);fill:none;stroke-width:2}
.success-msg{background:var(--green-bg);color:var(--green);border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;font-size:0.83rem;margin-bottom:16px;display:none}
</style>
</head>
<body>
<div class="bg-pattern"></div>
<div class="bg-grid"></div>
<div class="brand-strip">
  <div class="brand-logo">
    <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
  </div>
  <span class="brand-name">EduTrack</span>
  <span class="brand-tag">Student Attendance ERP</span>
</div>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-header">
      <div class="login-icon">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      </div>
      <h1 class="login-title">Teacher Portal</h1>
      <p class="login-sub">Sign in to manage your classes & attendance</p>
    </div>
<?php if($error!=""){ ?>
<div class="error-msg" style="display:block;">
    <?php echo $error; ?>
</div>
<?php } ?>
    <form method="POST">

<div class="form-group">
<label>Username / Employee ID</label>
      <input type="text" id="username" name="username" placeholder="e.g. TCH-2024-001" autocomplete="username" required>
    </div>
    <div class="form-group">
<label>Password</label>
      <div class="input-icon-wrap">
        <input type="password"
id="password"
name="password"
placeholder="Enter your password"
autocomplete="current-password"
required>
        <span class="input-icon" onclick="togglePass()">
          <svg id="eyeIcon" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        </span>
      </div>
    </div>
    <div class="forgot"><a href="#">Forgot Password?</a></div>
    <button class="btn-login" type="submit" name="login">Sign In to Dashboard</button>
    </form>
    <div class="divider">or</div>
<button type="button" class="btn-create" onclick="document.getElementById('createModal').classList.add('open')">
    Create New Account
</button>  </div>
</div>

<!-- Create Account Modal -->
<div class="modal-overlay" id="createModal">
  <div class="modal">
    <div class="modal-header">
      <h2 class="modal-title">Create Account</h2>
      <div class="modal-close" onclick="document.getElementById('createModal').classList.remove('open')">
        <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </div>
    </div>
    <?php if ($success != "") { ?>
    <div class="success-msg" style="display:block;">
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php } ?>

<?php if ($create_error != "") { ?>
    <div class="error-msg" style="display:block;">
        <?php echo htmlspecialchars($create_error); ?>
    </div>
<?php } ?>

<form method="POST">

    <div class="form-group">
        <label>Full Name</label>
        <input
            type="text"
            name="teacher_name"
            placeholder="Dr. Anita Sharma"
            required
        >
    </div>

    <div class="form-group">
        <label>Employee ID</label>
        <input
            type="text"
            name="employee_id"
            placeholder="TCH-2024-001"
            required
        >
    </div>
    <div class="form-group">
    <label>Email</label>
    <input
        type="email"
        name="email"
        placeholder="teacher@example.com"
        required
    >
</div>

    <div class="form-group">
        <label>Department</label>
        <input
            type="text"
            name="department"
            placeholder="Computer Science & Engineering"
            required
        >
    </div>

    <div class="form-group">
        <label>Password</label>
        <input
            type="password"
            name="create_password"
            placeholder="Create a strong password"
            required
        >
    </div>

    <button
        type="submit"
        name="create_account"
        class="btn-login"
    >
        Create Account
    </button>

</form>
</div>
</div>

<script>
function togglePass(){
  const inp=document.getElementById('password');
  inp.type=inp.type==='password'?'text':'password';
}

</script>
</body>
</html>
