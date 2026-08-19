<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Location: reception-dash.php');
    exit;
}

$search=trim($_POST['search'] ?? '');

if(empty($search)){
    $_SESSION['error']='Please enter a Patient ID or NRC.';
    header('Location: reception-dash.php');
    exit;
}

$stmt=$conn->prepare("
    SELECT patient_id
    FROM patients
    WHERE patient_id=? OR nrc=?
    LIMIT 1
");
$stmt->bind_param("ss",$search,$search);
$stmt->execute();
$result=$stmt->get_result();

if($result->num_rows===0){
    $_SESSION['error']='Patient not found.';
    header('Location: reception-dash.php');
    exit;
}

$patient=$result->fetch_assoc();
$patient_id=$patient['patient_id'];

$stmt->close();

$check=$conn->prepare("
    SELECT queue_id
    FROM reception_queue
    WHERE patient_id=? AND status='Waiting'
    LIMIT 1
");
$check->bind_param("s",$patient_id);
$check->execute();
$check->store_result();

if($check->num_rows>0){
    $_SESSION['error']='Patient is already waiting for the nurse.';
    $check->close();
    header('Location: reception-dash.php');
    exit;
}

$check->close();

$insert=$conn->prepare("
    INSERT INTO reception_queue
    (
        patient_id,
        status,
        check_in_time
    )
    VALUES
    (
        ?,
        'Waiting',
        NOW()
    )
");
$insert->bind_param("s",$patient_id);

if($insert->execute()){
    $_SESSION['success']='Patient successfully sent to nurse.';
}else{
    $_SESSION['error']='Failed to send patient to nurse.';
}

$insert->close();

header('Location: reception-dash.php');
exit;
?>
