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

if(isset($_POST['submit'])){

$class_id = $_POST['class_id'];
$reason = $_POST['reason'];

$filename = $_FILES['proof']['name'];
$tempname = $_FILES['proof']['tmp_name'];

$folder = "uploads/".$filename;

move_uploaded_file($tempname,$folder);

$query = "INSERT INTO absence_reasons(student_id,class_id,reason,proof_file)
VALUES ('$student_id','$class_id','$reason','$filename')";

mysqli_query($conn,$query);

echo "Reason submitted successfully";

}

?>

<h2>Submit Reason for Absence</h2>

<form method="POST" enctype="multipart/form-data">

Class ID:
<input type="number" name="class_id" required>
<br><br>

Reason:
<textarea name="reason" required></textarea>
<br><br>

Upload Proof:
<input type="file" name="proof">
<br><br>

<button type="submit" name="submit">Submit</button>

</form>