<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$doctor_id = $_SESSION['user_id'];
$queue_id = $_POST['queue_id'] ?? null;

if (!$queue_id) {
    echo json_encode(['success' => false, 'error' => 'Missing queue_id']);
    exit;
}

$stmt = $conn->prepare("
    UPDATE doctor_queue
    SET doctor_id = ?, assigned_at = NOW()
    WHERE queue_id = ?
    AND doctor_id IS NULL
");

$stmt->bind_param("ss", $doctor_id, $queue_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Already assigned']);
}

$stmt->close();