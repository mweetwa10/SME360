<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Receptionist') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$patient_id = trim($_POST['patient_id'] ?? '');

if (!$patient_id) {
    echo json_encode(['success' => false, 'message' => 'Patient ID required.']);
    exit;
}

$chk = $conn->prepare("
    SELECT queue_id FROM reception_queue
    WHERE patient_id = ? AND DATE(check_in_time) = CURDATE()
    LIMIT 1
");
$chk->bind_param("s", $patient_id);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Patient is already checked in today.']);
    $chk->close();
    exit;
}
$chk->close();

$stmt = $conn->prepare("
    INSERT INTO reception_queue (patient_id, check_in_time, status)
    VALUES (?, NOW(), 'waiting')
");
$stmt->bind_param("s", $patient_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Patient checked in successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to check in patient.']);
}
$stmt->close();