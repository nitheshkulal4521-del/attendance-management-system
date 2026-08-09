<?php

require_once __DIR__ . "/config.php";

$conn = mysqli_connect(
    $host,
    $username,
    $password,
    $db_name
);

if (!$conn) {
    die("Connection failed");
}
$student_id = 1;

$semester = $_GET['semester'];

?>


<h1>Select Semester</h1>

<a href="student_dashboard.php?semester=1">Semester 1</a><br><br>

<a href="student_dashboard.php?semester=2">Semester 2</a><br><br>

<a href="student_dashboard.php?semester=3">Semester 3</a><br><br>

<a href="student_dashboard.php?semester=4">Semester 4</a><br><br>

<a href="student_dashboard.php?semester=5">Semester 5</a><br><br>

<a href="student_dashboard.php?semester=6">Semester 6</a><br><br>

<a href="student_dashboard.php?semester=7">Semester 7</a><br><br>

<a href="student_dashboard.php?semester=8">Semester 8</a>