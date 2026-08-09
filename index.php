<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Attendance Management System</title>

<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">

<style>

:root{
    --blue:#2563eb;
    --blue-dark:#1d4ed8;
    --blue-bg:#eff6ff;
    --slate:#0f172a;
    --muted:#64748b;
    --border:#e2e8f0;
    --bg:#f8fafc;
}

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{

    font-family:'DM Sans',sans-serif;

    background:linear-gradient(135deg,#eff6ff,#ffffff);

    min-height:100vh;

    display:flex;

    align-items:center;

    justify-content:center;

    padding:30px;

}

.container{

    width:100%;

    max-width:1100px;

}

.header{

    text-align:center;

    margin-bottom:50px;

}

.logo{

    width:90px;
    height:90px;

    border-radius:20px;

    background:#2563eb;

    color:white;

    display:flex;

    align-items:center;

    justify-content:center;

    font-size:42px;

    margin:auto;

    margin-bottom:22px;

    box-shadow:0 12px 30px rgba(37,99,235,.25);

}

.header h1{

    font-family:'DM Serif Display',serif;

    font-size:40px;

    color:#0f172a;

}

.header p{

    margin-top:12px;

    color:#64748b;

    font-size:16px;

}

.cards{

    display:grid;

    grid-template-columns:repeat(2,1fr);

    gap:30px;

}

.card{

    background:white;

    border-radius:18px;

    padding:40px;

    text-align:center;

    border:1px solid #e2e8f0;

    transition:.25s;

    box-shadow:0 10px 25px rgba(0,0,0,.06);

}

.card:hover{

    transform:translateY(-8px);

    box-shadow:0 18px 40px rgba(37,99,235,.15);

    border-color:#2563eb;

}

.icon{

    font-size:70px;

    margin-bottom:18px;

}

.card h2{

    font-size:28px;

    margin-bottom:10px;

    color:#0f172a;

}

.card p{

    color:#64748b;

    line-height:1.7;

    margin-bottom:28px;

}

.btn{

    display:inline-block;

    background:#2563eb;

    color:white;

    text-decoration:none;

    padding:14px 34px;

    border-radius:12px;

    font-weight:600;

    transition:.2s;

}

.btn:hover{

    background:#1d4ed8;

}

.footer{

    margin-top:50px;

    text-align:center;

    color:#64748b;

    font-size:14px;

}

@media(max-width:768px){

.cards{

grid-template-columns:1fr;

}

.header h1{

font-size:30px;

}

.card{

padding:28px;

}

}

</style>

</head>

<body>

<div class="container">

<div class="header">

<div class="logo">

🎓

</div>

<h1>

Attendance Management System

</h1>

<p>

Srinivas Institute of Technology<br>



</p>

</div>

<div class="cards">

<div class="card">

<div class="icon">

👨‍🏫

</div>

<h2>

Teacher Portal

</h2>

<p>

Manage students, attendance, reports, shortage list, grace attendance and certificates.

</p>

<a
href="teacher_login.php"
class="btn"
>

Teacher Login

</a>

</div>

<div class="card">

<div class="icon">

👨‍🎓

</div>

<h2>

Student Portal

</h2>

<p>

View attendance, attendance summary, upload absence proof and monitor attendance status.

</p>

<a
href="student_login.html"
class="btn"
>

Student Login

</a>

</div>

</div>

<div class="footer">

© 2026 Attendance Management System<br>

Designed for Srinivas Institute of Technology

</div>

</div>

</body>

</html>