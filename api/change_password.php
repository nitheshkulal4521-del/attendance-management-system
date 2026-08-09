<?php
header("Content-Type: application/json");
require_once "db.php";

$data = json_decode(file_get_contents("php://input"), true);

// Fallback for form-data or POST requests
if (!$data) {
    $data = $_POST;
}


$user_id  = intval($data["user_id"] ?? 0);
$role     = trim($data["role"] ?? "");

$current  = trim($data["current_password"] ?? "");
$new      = trim($data["new_password"] ?? "");
$confirm  = trim($data["confirm_password"] ?? "");

if (!$user_id || empty($role) || empty($current) || empty($new) || empty($confirm)) {
    echo json_encode([
        "success" => false,
        "message" => "All fields are required."
    ]);
    exit();
}

if ($new !== $confirm) {
    echo json_encode([
        "success" => false,
        "message" => "New passwords do not match."
    ]);
    exit();
}

/* Find the user */
$stmt = $conn->prepare("
    SELECT password
    FROM users
    WHERE user_id = ?
      AND role = ?
");

$stmt->bind_param("is", $user_id, $role);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {

    echo json_encode([
        "success" => false,
        "message" => "User not found."
    ]);
    exit();
}

$user = $result->fetch_assoc();

/* Verify current password
   (Currently your project stores plain text passwords) */
if (!password_verify($current, $user["password"])) {

    echo json_encode([
        "success" => false,
        "message" => "Current password is incorrect."
    ]);
    exit();
}

/* Update password */
$hashedPassword = password_hash($new, PASSWORD_DEFAULT);

$update = $conn->prepare("
    UPDATE users
    SET password = ?
    WHERE user_id = ?
");

$update->bind_param("si", $hashedPassword, $user_id);

if ($update->execute()) {

    echo json_encode([
        "success" => true,
        "message" => "Password updated successfully."
    ]);

} else {

    echo json_encode([
        "success" => false,
        "message" => "Unable to update password."
    ]);
}

$conn->close();
?>