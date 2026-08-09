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
$student_id = 1; // temporary for testing
?>

<?php

$query = "
SELECT 
s.subject_name,
COUNT(*) AS total_classes,
SUM(a.status='Present') AS attended,
SUM(a.status='Absent') AS absent
FROM attendance a
JOIN classes c ON a.class_id = c.class_id
JOIN subjects s ON c.subject_id = s.subject_id
WHERE a.student_id = $student_id
GROUP BY s.subject_name
";

$result = mysqli_query($conn,$query);

?>

<h1>Subject-wise Attendance</h1>

<table border="1">

<tr>
<th>Subject</th>
<th>Total Classes</th>
<th>Attended</th>
<th>Absent</th>
</tr>

<?php

while($row = mysqli_fetch_assoc($result)){

echo "<tr>";

echo "<td>".$row['subject_name']."</td>";

echo "<td>".$row['total_classes']."</td>";

echo "<td>".$row['attended']."</td>";

echo "<td>".$row['absent']."</td>";

echo "</tr>";

}

?>

</table>