<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    http_response_code(403);
    exit;
}

$doctor_id = $_SESSION['user_id'];
$status = $_POST['status'] ?? '';

if (!in_array($status, ['Available', 'Busy', 'Off-duty'])) {
    echo json_encode(["success" => false, "error" => "Invalid status"]);
    exit;
}

$stmt = $conn->prepare("UPDATE doctors SET status = ? WHERE doctor_id = ?");
$stmt->bind_param("ss", $status, $doctor_id);
$stmt->execute();
$stmt->close();

if ($status === 'Available') {

    $query = $conn->query("
        SELECT queue_id, patient_id
        FROM doctor_queue
        WHERE status = 'Waiting'
        AND doctor_id IS NULL
        ORDER BY assigned_at ASC
        LIMIT 1
    ");

    if ($query && $query->num_rows > 0) {
        $row = $query->fetch_assoc();

        $queue_id = $row['queue_id'];
        $patient_id = $row['patient_id'];

        $stmt = $conn->prepare("
            UPDATE doctor_queue
            SET doctor_id = ?, status = 'Assigned'
            WHERE queue_id = ?
        ");
        $stmt->bind_param("si", $doctor_id, $queue_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE nurse_vitals
            SET doctor_id = ?
            WHERE patient_id = ?
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $stmt->bind_param("ss", $doctor_id, $patient_id);
        $stmt->execute();
        $stmt->close();

        $conn->query("UPDATE doctors SET status = 'Busy' WHERE doctor_id = '$doctor_id'");
    }
}

echo json_encode(["success" => true]);