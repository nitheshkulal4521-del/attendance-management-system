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

if(isset($_GET['semester'])){
    $semester = $_GET['semester'];
}else{
    echo "Semester not selected";
    exit();
}

$query = "
SELECT 
COUNT(*) AS total_classes,
SUM(a.status='Present') AS attended,
SUM(a.status='Absent') AS absent
FROM attendance a
JOIN classes c ON a.class_id = c.class_id
WHERE a.student_id = $student_id
AND c.semester = $semester
";

$result = mysqli_query($conn,$query);
$data = mysqli_fetch_assoc($result);

$total = $data['total_classes'];
$attended = $data['attended'];
$absent = $data['absent'];

$percentage = 0;

if($total > 0){
    $percentage = ($attended / $total) * 100;
}

?>

<h1>Student Attendance Dashboard</h1>

<p>Total Classes: <?php echo $total; ?></p>

<p>Classes Attended: <?php echo $attended; ?></p>

<p>Classes Absent: <?php echo $absent; ?></p>

<p>Attendance Percentage: <?php echo round($percentage,2); ?>%</p>