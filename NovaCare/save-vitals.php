<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Africa/Lusaka');

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Nurse') {
    $_SESSION['error'] = "You must be logged in as a nurse.";
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: nurse-dash.php");
    exit;
}

$nurse_id = $_SESSION['user_id'];

$patient_id  = trim($_POST['patient_id']  ?? '');
$doctor_id   = trim($_POST['doctor_id']   ?? '');  
$doctor_type = trim($_POST['doctor_type'] ?? 'general'); 

$temperature    = trim($_POST['temperature']    ?? '');
$blood_pressure = trim($_POST['blood_pressure'] ?? '');
$pulse          = trim($_POST['pulse']          ?? '');

$respiratory_rate = (isset($_POST['respiratory_rate']) && $_POST['respiratory_rate'] !== '')
    ? (int) trim($_POST['respiratory_rate'])
    : null;

$weight = (isset($_POST['weight']) && $_POST['weight'] !== '')
    ? (float) trim($_POST['weight'])
    : null;

$notes = trim($_POST['notes'] ?? '');

if ($patient_id === '' || $temperature === '' || $blood_pressure === '' || $pulse === '') {
    $_SESSION['error'] = "Please fill in all required fields (Temperature, Blood Pressure, Pulse).";
    header("Location: nurse-dash.php");
    exit;
}

$temperature = (float) $temperature;
$pulse       = (int)   $pulse;

$doctor_id_db = ($doctor_id === '') ? null : $doctor_id;

$dupCheck = $conn->prepare("
    SELECT vitals_id FROM nurse_vitals
    WHERE patient_id = ?
      AND recorded_at >= NOW() - INTERVAL 10 MINUTE
    LIMIT 1
");
$dupCheck->bind_param("s", $patient_id);
$dupCheck->execute();
$dupCheck->store_result();
if ($dupCheck->num_rows > 0) {
    $dupCheck->close();
    $_SESSION['error'] = "Vitals were already recorded for this patient in the last 10 minutes.";
    header("Location: nurse-dash.php");
    exit;
}
$dupCheck->close();

$stmt = $conn->prepare("
    INSERT INTO nurse_vitals
        (patient_id, doctor_id, nurse_id, temperature, blood_pressure,
         pulse, respiratory_rate, weight, notes, recorded_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

$stmt->bind_param(
    "sssdsiids",
    $patient_id,
    $doctor_id_db,
    $nurse_id,
    $temperature,
    $blood_pressure,
    $pulse,
    $respiratory_rate,
    $weight,
    $notes
);

if (!$stmt->execute()) {
    $_SESSION['error'] = "Failed to save vitals: " . $stmt->error;
    $stmt->close();
    header("Location: nurse-dash.php");
    exit;
}
$stmt->close();

$updateQueue = $conn->prepare("
    UPDATE reception_queue
    SET status = 'Vitals_Recorded'
    WHERE patient_id = ? AND status = 'Waiting'
");
$updateQueue->bind_param("s", $patient_id);
$updateQueue->execute();
$updateQueue->close();

$dupQueue = $conn->prepare("
    SELECT queue_id FROM doctor_queue
    WHERE patient_id = ? AND status = 'Waiting'
    LIMIT 1
");
$dupQueue->bind_param("s", $patient_id);
$dupQueue->execute();
$dupQueue->store_result();
$alreadyQueued = ($dupQueue->num_rows > 0);
$dupQueue->close();

if (!$alreadyQueued) {
    if ($doctor_id_db !== null) {
        
        $doctorQueue = $conn->prepare("
            INSERT INTO doctor_queue (patient_id, doctor_id, status, assigned_at)
            VALUES (?, ?, 'Waiting', NOW())
        ");
        $doctorQueue->bind_param("ss", $patient_id, $doctor_id_db);
        $doctorQueue->execute();
        $doctorQueue->close();

        
        $updateDoctor = $conn->prepare("
            UPDATE doctors SET status = 'Busy' WHERE doctor_id = ?
        ");
        $updateDoctor->bind_param("s", $doctor_id_db);
        $updateDoctor->execute();
        $updateDoctor->close();

        $_SESSION['success'] = "Vitals recorded and patient assigned to doctor.";

    } else {
        
        $doctorQueue = $conn->prepare("
            INSERT INTO doctor_queue (patient_id, doctor_id, status, assigned_at)
            VALUES (?, NULL, 'Waiting', NOW())
        ");
        $doctorQueue->bind_param("s", $patient_id);
        $doctorQueue->execute();
        $doctorQueue->close();

        $_SESSION['success'] = "Vitals recorded. Patient queued for next available doctor.";
    }
} else {
    $_SESSION['success'] = "Vitals recorded. Patient was already in the doctor queue.";
}

header("Location: nurse-dash.php");
exit;