<?php

session_start();
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}

$lab_request_id = $_POST['lab_request_id'];
$payment_status = $_POST['payment_status'];

if(
    $payment_status != 'Pending' &&
    $payment_status != 'Paid'
){
    exit;
}

$stmt = $conn->prepare("
    UPDATE lab_requests
    SET payment_status = ?
    WHERE lab_request_id = ?
");

$stmt->bind_param(
    "si",
    $payment_status,
    $lab_request_id
);

$stmt->execute();

$stmt->close();

header("Location: lab-dash.php");
exit;
?>