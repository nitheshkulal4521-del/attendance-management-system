<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "db.php";

$proof_id   = intval($_POST["proof_id"] ?? 0);
$proof_type = trim($_POST["proof_type"] ?? "");
$remarks    = trim($_POST["remarks"] ?? "");

if (!$proof_id || empty($proof_type)) {
    echo json_encode([
        "success" => false,
        "message" => "Missing required fields."
    ]);
    exit();
}

/* -------------------------------------------------------
   Fetch existing proof details
------------------------------------------------------- */

$stmt = $conn->prepare("
    SELECT student_id, attendance_id, file_path
    FROM attendance_proofs
    WHERE proof_id = ?
");

$stmt->bind_param("i", $proof_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode([
        "success" => false,
        "message" => "Proof not found."
    ]);
    exit();
}

$proof = $result->fetch_assoc();

$student_id    = $proof["student_id"];
$attendance_id = $proof["attendance_id"];
$filePath      = $proof["file_path"];

/* -------------------------------------------------------
   Upload folder
------------------------------------------------------- */

$uploadDir = "../uploads/proofs/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/* -------------------------------------------------------
   If a new file is uploaded, replace old one
------------------------------------------------------- */

if (
    isset($_FILES["proof_file"]) &&
    $_FILES["proof_file"]["error"] == UPLOAD_ERR_OK
) {

    $file = $_FILES["proof_file"];

    $allowed = ["pdf", "jpg", "jpeg", "png", "doc", "docx"];
    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        echo json_encode([
            "success" => false,
            "message" => "File type not allowed. Use PDF, JPG, PNG, DOC, or DOCX."
        ]);
        exit();
    }

    if ($file["size"] > 5 * 1024 * 1024) {
        echo json_encode([
            "success" => false,
            "message" => "File size must be under 5 MB."
        ]);
        exit();
    }

    $newFileName = "proof_" .
        $student_id . "_" .
        $attendance_id . "_" .
        time() . "." . $ext;

    $newFilePath = $uploadDir . $newFileName;

    if (!move_uploaded_file($file["tmp_name"], $newFilePath)) {
        echo json_encode([
            "success" => false,
            "message" => "Could not save file on server."
        ]);
        exit();
    }

    // Delete old file
    if (!empty($filePath) && file_exists($filePath)) {
        unlink($filePath);
    }

    $filePath = $newFilePath;
}

/* -------------------------------------------------------
   Update database
------------------------------------------------------- */

$stmt = $conn->prepare("
    UPDATE attendance_proofs
    SET
        proof_type = ?,
        remarks = ?,
        file_path = ?,
        status = 'pending'
    WHERE proof_id = ?
");

$stmt->bind_param(
    "sssi",
    $proof_type,
    $remarks,
    $filePath,
    $proof_id
);

if ($stmt->execute()) {

    echo json_encode([
        "success" => true,
        "message" => "Proof updated successfully."
    ]);

} else {

    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $conn->error
    ]);

}

$conn->close();
?>