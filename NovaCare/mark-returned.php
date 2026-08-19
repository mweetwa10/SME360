<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if(!isset($_POST['referral_id'])){

    echo json_encode([
        'status'=>'error',
        'message'=>'Referral ID missing'
    ]);

    exit;
}

$referral_id =
mysqli_real_escape_string(
    $conn,
    $_POST['referral_id']
);

$update = mysqli_query($conn,"
    UPDATE referrals
    SET
        returned_status='Returned',
        status='Completed'
    WHERE referral_id='$referral_id'
");

if(!$update){

    echo json_encode([
        'status'=>'error',
        'message'=>mysqli_error($conn)
    ]);

    exit;
}

echo json_encode([
    'status'=>'success',
    'message'=>'Patient marked as returned.'
]);