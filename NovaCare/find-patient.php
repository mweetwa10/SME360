<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_POST['search']) || empty($_POST['search'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No search term provided'
    ]);
    exit;
}

$search = mysqli_real_escape_string($conn, $_POST['search']);

$query = mysqli_query($conn, "
    SELECT patient_id, full_name, nrc, phone
    FROM patients
    WHERE patient_id = '$search'
    OR nrc = '$search'
    LIMIT 1
");

if (!$query || mysqli_num_rows($query) == 0) {
    echo json_encode([
        'status' => 'not_found',
        'message' => 'Patient not found'
    ]);
    exit;
}

$patient = mysqli_fetch_assoc($query);
$patient_id = $patient['patient_id'];

$check = mysqli_query($conn, "
    SELECT referral_id
    FROM referrals
    WHERE patient_id = '$patient_id'
    AND returned_status = 'Not Returned'
    LIMIT 1
");

if (mysqli_num_rows($check) > 0) {
    echo json_encode([
        'status' => 'blocked',
        'message' => 'Patient already has an active referral and cannot be referred again until returned'
    ]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'patient' => [
        'patient_id' => $patient['patient_id'],
        'full_name'  => $patient['full_name'],
        'nrc'        => $patient['nrc'],
        'phone'      => $patient['phone']
    ]
]);

exit;