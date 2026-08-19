<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

$doctor_id = $_SESSION['user_id'];

$patient_id      = $_POST['patient_id'] ?? '';
$test_name       = $_POST['test_name'] ?? '';
$clinical_notes  = $_POST['clinical_notes'] ?? '';

if (empty($patient_id) || empty($test_name)) {
    echo json_encode(["success" => false, "error" => "Missing required fields"]);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO lab_requests 
    (patient_id, doctor_id, test_name, clinical_notes, status, requested_at)
    VALUES (?, ?, ?, ?, 'Pending', NOW())
");

$stmt->bind_param("ssss", $patient_id, $doctor_id, $test_name, $clinical_notes);

if ($stmt->execute()) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "error" => $stmt->error]);
}

$stmt->close();
?>