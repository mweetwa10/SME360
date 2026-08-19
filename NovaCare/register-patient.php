<?php
session_start();

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header("Location: reception-dash.php");
    exit;
}



$full_name = trim($_POST['full_name']);

$email = trim($_POST['email']);

$gender = $_POST['gender'];

$dob = $_POST['dob'];

$phone = trim($_POST['phone']);

$nrc = trim($_POST['nrc']);

$nok_name = trim($_POST['nok_full_name']);

$nok_phone = trim($_POST['nok_phone']);


if(
    empty($full_name) ||
    empty($email) ||
    empty($gender) ||
    empty($nrc)
){

    $_SESSION['error'] =
    "Please fill in all required fields.";

    header("Location: reception-dash.php");
    exit;
}

$checkEmail = $conn->prepare("
    SELECT patient_id
    FROM patients
    WHERE email = ?
");

$checkEmail->bind_param(
    "s",
    $email
);

$checkEmail->execute();

$checkEmail->store_result();

if($checkEmail->num_rows > 0){

    $_SESSION['error'] =
    "Email already exists.";

    header("Location: reception-dash.php");
    exit;
}

$checkEmail->close();


$check = $conn->prepare("
    SELECT patient_id
    FROM patients
    WHERE phone = ?
    OR nrc = ?
");

$check->bind_param(
    "ss",
    $phone,
    $nrc
);

$check->execute();

$check->store_result();

if ($check->num_rows > 0) {

    $_SESSION['error'] =
    "Patient with same NRC or phone number already exists.";

    header("Location: reception-dash.php");
    exit;
}

$check->close();

$year = date('Y');

$prefix = "PAT$year";

$result = $conn->query("
    SELECT patient_id
    FROM patients
    WHERE patient_id LIKE '$prefix%'
    ORDER BY patient_id DESC
    LIMIT 1
");

if ($result->num_rows === 1) {

    $lastId =
    $result->fetch_assoc()['patient_id'];

    $number =
    intval(substr($lastId, -3)) + 1;

} else {

    $number = 1;
}

$patient_id =
$prefix .
str_pad($number, 3, '0', STR_PAD_LEFT);

$stmt = $conn->prepare("
    INSERT INTO patients
    (
        patient_id,
        full_name,
        email,
        gender,
        dob,
        phone,
        nrc,
        nok_full_name,
        nok_phone,
        created_at
    )
    VALUES
    (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
    )
");

$stmt->bind_param(
    "sssssssss",
    $patient_id,
    $full_name,
    $email,
    $gender,
    $dob,
    $phone,
    $nrc,
    $nok_name,
    $nok_phone
);



if ($stmt->execute()) {
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0"
>

<title>
Patient Card
</title>

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:"Segoe UI",Tahoma,Verdana,sans-serif;
}

body{
    background:#f4f7fb;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
    flex-direction:column;
}

.patient-card{

    width:340px;
    height:210px;

    background:#0f4c81;

    border-radius:16px;

    color:#fff;

    padding:22px;

    position:relative;

    box-shadow:0 10px 25px rgba(0,0,0,.2);

    overflow:hidden;
}

.patient-card::before{

    content:"";

    position:absolute;

    width:180px;
    height:180px;

    background:rgba(255,255,255,.08);

    border-radius:50%;

    top:-70px;
    right:-60px;
}

.company{

    font-size:24px;

    font-weight:700;

    margin-bottom:28px;

    letter-spacing:1px;
}

.label{

    font-size:11px;

    opacity:.8;

    margin-bottom:4px;

    text-transform:uppercase;
}

.value{

    font-size:19px;

    font-weight:700;

    margin-bottom:16px;
}

.footer{

    position:absolute;

    bottom:15px;

    right:20px;

    font-size:11px;

    opacity:.8;
}

.actions{

    margin-top:25px;

    display:flex;

    gap:15px;
}

.btn{

    padding:12px 20px;

    border:none;

    border-radius:8px;

    cursor:pointer;

    font-weight:600;

    font-size:14px;

    text-decoration:none;

    display:inline-block;
}

.print-btn{

    background:#1fa2ff;

    color:#fff;
}

.print-btn:hover{

    background:#1685d9;
}

.back-btn{

    background:#e5e7eb;

    color:#111827;
}

.back-btn:hover{

    background:#d1d5db;
}

@media print{

    body{
        background:#fff;
    }

    .actions{
        display:none;
    }

    .patient-card{

        box-shadow:none;

        width:320px;
        height:200px;
    }
}

</style>

</head>

<body>

<div class="patient-card">

<div class="company">
MediPulse
</div>



<div class="label">
Patient Name
</div>

<div class="value">
<?= htmlspecialchars($full_name) ?>
</div>



<div class="label">
Patient ID
</div>

<div class="value">
<?= htmlspecialchars($patient_id) ?>
</div>

<div class="footer">
Healthcare Management System
</div>

</div>

<div class="actions">

<button
class="btn print-btn"
onclick="window.print()"
>

Print Patient Card

</button>

<a
href="reception-dash.php"
class="btn back-btn"
>

Back to Dashboard

</a>

</div>

</body>

</html>

<?php

} else {

    $_SESSION['error'] =
    "Failed to register patient.";

    header("Location: reception-dash.php");
    exit;
}

$stmt->close();
?>