<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Lab Staff') {
    echo json_encode([
        "success" => false,
        "error" => "Unauthorized access"
    ]);
    exit;
}

$lab_staff_id   = $_SESSION['user_id'];
$lab_request_id = $_POST['lab_request_id'] ?? '';
$result         = $_POST['result'] ?? '';

if (empty($lab_request_id) || empty($result)) {
    echo json_encode([
        "success" => false,
        "error" => "Missing required fields"
    ]);
    exit;
}

$get = $conn->prepare("
    SELECT patient_id 
    FROM lab_requests 
    WHERE lab_request_id = ?
");

$get->bind_param("s", $lab_request_id);
$get->execute();
$res = $get->get_result();
$row = $res->fetch_assoc();

if (!$row) {
    echo json_encode([
        "success" => false,
        "error" => "Invalid lab request"
    ]);
    exit;
}

$patient_id = $row['patient_id'];
$get->close();

$check = $conn->prepare("
    SELECT lab_result_id 
    FROM lab_results 
    WHERE lab_request_id = ?
");

$check->bind_param("s", $lab_request_id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode([
        "success" => false,
        "error" => "Result already recorded"
    ]);
    exit;
}
$check->close();

$stmt = $conn->prepare("
    INSERT INTO lab_results 
    (lab_request_id, patient_id, lab_staff_id, result, result_date)
    VALUES (?, ?, ?, ?, NOW())
");

$stmt->bind_param(
    "ssss",
    $lab_request_id,
    $patient_id,
    $lab_staff_id,
    $result
);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Lab result saved successfully"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "error" => $stmt->error
    ]);
}

$stmt->close();
?>