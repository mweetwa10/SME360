<?php
error_reporting(0);
ini_set('display_errors', 0);

ob_start();
session_start();
include 'db.php';
ob_end_clean();

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

if (!isset($conn) || !$conn) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        lr.lab_result_id,
        lreq.test_name,
        lr.result,
        DATE_FORMAT(lr.result_date, '%d %b %Y %H:%i') AS result_date,
        COALESCE(ls.full_name, 'Lab Staff') AS lab_staff
    FROM lab_results lr
    LEFT JOIN lab_requests lreq ON lr.lab_request_id = lreq.lab_request_id
    LEFT JOIN lab_staff    ls   ON lr.lab_staff_id   = ls.lab_staff_id
    WHERE lr.patient_id = ?
    ORDER BY lr.result_date DESC
");

if (!$stmt) {
    echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param("s", $patient_id);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) $rows[] = $row;
$stmt->close();

echo json_encode($rows);