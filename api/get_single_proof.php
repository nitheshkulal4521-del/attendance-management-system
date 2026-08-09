<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

require_once "db.php";

$attendance_id = intval($_GET["attendance_id"] ?? 0);

if (!$attendance_id) {
    echo json_encode([
        "success" => false,
        "message" => "attendance_id is required."
    ]);
    exit();
}

$sql = "SELECT
            proof_id,
            attendance_id,
            student_id,
            proof_type,
            remarks,
            file_path,
            status,
            submitted_at
        FROM attendance_proofs
        WHERE attendance_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $attendance_id);
$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    echo json_encode([
        "success" => true,
        "proof" => $row
    ]);

} else {

    echo json_encode([
        "success" => false,
        "message" => "Proof not found."
    ]);

}

$conn->close();
?>