<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized access'
    ]);
    exit;
}

if (!isset($_POST['patient_id']) || empty($_POST['patient_id'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Patient ID missing'
    ]);
    exit;
}

$doctor_id = (int) $_SESSION['user_id'];
$patient_id = mysqli_real_escape_string($conn, $_POST['patient_id']);

$hospital  = mysqli_real_escape_string($conn, $_POST['hospital'] ?? '');
$urgency   = mysqli_real_escape_string($conn, $_POST['urgency'] ?? '');
$diagnosis = mysqli_real_escape_string($conn, $_POST['diagnosis'] ?? '');
$treatment = mysqli_real_escape_string($conn, $_POST['treatment'] ?? '');
$reason    = mysqli_real_escape_string($conn, $_POST['reason'] ?? '');
$notes     = mysqli_real_escape_string($conn, $_POST['notes'] ?? '');

$check = mysqli_query($conn, "
    SELECT referral_id
    FROM referrals
    WHERE patient_id = '$patient_id'
    AND returned_status = 'Not Returned'
    LIMIT 1
");

if (mysqli_num_rows($check) > 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Patient already has an active referral'
    ]);
    exit;
}

$save = mysqli_query($conn, "
    INSERT INTO referrals (
        patient_id,
        doctor_id,
        referred_hospital,
        diagnosis,
        treatment_given,
        referral_reason,
        notes,
        urgency,
        referral_date,
        status,
        returned_status
    )
    VALUES (
        '$patient_id',
        $doctor_id,
        '$hospital',
        '$diagnosis',
        '$treatment',
        '$reason',
        '$notes',
        '$urgency',
        NOW(),
        'Pending',
        'Not Returned'
    )
");

if (!$save) {
    echo json_encode([
        'status' => 'error',
        'message' => mysqli_error($conn)
    ]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'message' => 'Referral saved successfully'
]);
exit;