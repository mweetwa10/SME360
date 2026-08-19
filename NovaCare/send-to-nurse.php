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
    SELECT queue_id, status FROM reception_queue
    WHERE patient_id = ? AND DATE(check_in_time) = CURDATE()
    ORDER BY check_in_time DESC
    LIMIT 1
");
$chk->bind_param("s", $patient_id);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Patient has not been checked in today. Please check in first.']);
    exit;
}

if ($row['status'] === 'sent_to_nurse') {
    echo json_encode(['success' => false, 'message' => 'Patient has already been sent to the nurse.']);
    exit;
}

if ($row['status'] === 'complete') {
    echo json_encode(['success' => false, 'message' => 'This visit is already marked complete.']);
    exit;
}

$stmt = $conn->prepare("
    UPDATE reception_queue
    SET status = 'sent_to_nurse'
    WHERE queue_id = ?
");
$stmt->bind_param("i", $row['queue_id']);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Patient sent to nurse for vitals.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update status.']);
}
$stmt->close();