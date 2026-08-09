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

$student_id = 2; // temporary for testing
?>

<?php

$query = "
SELECT 
s.subject_name,
c.class_date
FROM attendance a
JOIN classes c ON a.class_id = c.class_id
JOIN subjects s ON c.subject_id = s.subject_id
WHERE a.student_id = $student_id
AND a.status='Absent'
ORDER BY c.class_date
";

$result = mysqli_query($conn,$query);

?>

<h1>Absent Dates</h1>

<table border="1">

<tr>
<th>Subject</th>
<th>Absent Date</th>
</tr>

<?php

while($row = mysqli_fetch_assoc($result)){

echo "<tr>";

echo "<td>".$row['subject_name']."</td>";

echo "<td>".$row['class_date']."</td>";

echo "</tr>";

}

?>

</table>