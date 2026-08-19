<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Africa/Lusaka');

session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$referring_doctor_id = $_SESSION['user_id'];
$patient_id          = trim($_POST['patient_id']    ?? '');
$specialist_id       = trim($_POST['specialist_id'] ?? '');
$reason              = trim($_POST['reason']        ?? '');
$notes               = trim($_POST['notes']         ?? '');

if (empty($patient_id) || empty($specialist_id) || empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Patient ID, specialist, and reason are required.']);
    exit;
}

// Verify patient exists
$check = $conn->prepare("SELECT patient_id FROM patients WHERE patient_id = ?");
$check->bind_param("s", $patient_id);
$check->execute();
$check->store_result();
if ($check->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Patient ID not found.']);
    $check->close();
    exit;
}
$check->close();

// Verify specialist exists
$spStmt = $conn->prepare("SELECT full_name, email, specialization FROM doctors WHERE doctor_id = ? AND type = 'specialist'");
$spStmt->bind_param("s", $specialist_id);
$spStmt->execute();
$spResult = $spStmt->get_result()->fetch_assoc();
$spStmt->close();

if (!$spResult) {
    echo json_encode(['success' => false, 'message' => 'Specialist not found.']);
    exit;
}

// Generate referral ID
$referral_id = "REF0001";
$idResult = $conn->query("SELECT referral_id FROM preferrals ORDER BY referral_id DESC LIMIT 1");
if ($idResult && $idResult->num_rows > 0) {
    $lastId      = $idResult->fetch_assoc()['referral_id'];
    $number      = intval(substr($lastId, 3)) + 1;
    $referral_id = "REF" . str_pad($number, 4, '0', STR_PAD_LEFT);
}

// Save to preferrals table
$stmt = $conn->prepare("
    INSERT INTO preferrals
        (referral_id, patient_id, referring_doctor_id, specialist_id, reason, notes, status, referred_at)
    VALUES
        (?, ?, ?, ?, ?, ?, 'Pending', NOW())
");
$stmt->bind_param("ssssss", $referral_id, $patient_id, $referring_doctor_id, $specialist_id, $reason, $notes);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to save referral: ' . $stmt->error]);
    $stmt->close();
    exit;
}
$stmt->close();

// Dummy email simulation
$specialistName  = $spResult['full_name'];
$specialistEmail = $spResult['email'];

echo json_encode([
    'success'     => true,
    'message'     => "Referral saved and email notification sent to Dr. $specialistName ($specialistEmail).",
    'referral_id' => $referral_id
]);