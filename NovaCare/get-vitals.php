<?php
ob_start();
session_start();

include 'db.php';

ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$patient_id = trim($_GET['patient_id'] ?? '');
if (!$patient_id) {
    echo json_encode(['error' => 'No patient ID']);
    exit;
}

$stmt = $conn->prepare("
    SELECT temperature, blood_pressure, pulse, respiratory_rate, weight, notes,
           DATE_FORMAT(recorded_at, '%d %b %Y %H:%i') AS recorded_at
    FROM nurse_vitals
    WHERE patient_id = ?
    ORDER BY recorded_at DESC
    LIMIT 1
");

if (!$stmt) {
    echo json_encode(['error' => 'Query prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("s", $patient_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode($row ?? ['error' => 'No vitals found for: ' . $patient_id]);