<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'Doctor'
) {

    $_SESSION['error'] = "You must login as a doctor.";

    header("Location: login.php");
    exit;
}

$doctor_id = $_SESSION['user_id'];

$doctorName = "Doctor";

$stmt = $conn->prepare("
    SELECT full_name
    FROM doctors
    WHERE doctor_id = ?
");

$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$stmt->bind_result($doctorName);
$stmt->fetch();
$stmt->close();

$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));

$schedules = [];

$sql = "
    SELECT *
    FROM doctor_weekly_schedules
    WHERE doctor_id = ?
    AND week_start = ?
    ORDER BY schedule_date ASC
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ss",
    $doctor_id,
    $monday
);

$stmt->execute();

$result = $stmt->get_result();

while($row = $result->fetch_assoc()) {

    $schedules[] = $row;
}

$stmt->close();

$weekDays = [];

$current = strtotime($monday);

while($current <= strtotime($sunday)) {

    $weekDays[] = [
        'date' => date('Y-m-d', $current),
        'day'  => date('l', $current)
    ];

    $current = strtotime('+1 day', $current);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>
    MediPulse | My Weekly Schedule
</title>

<meta
name="viewport"
content="width=device-width, initial-scale=1.0">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:"Segoe UI",Tahoma,Verdana,sans-serif;
}

body{
    background:#f4f7fb;
    color:#333;
}

header{
    background:#0f4c81;
    color:#fff;
    padding:18px 30px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.container{
    display:flex;
    min-height:100vh;
}

.sidebar{
    width:240px;
    background:#fff;
    box-shadow:2px 0 10px rgba(0,0,0,.05);
    padding-top:20px;
}

.sidebar ul{
    list-style:none;
}

.sidebar ul li{
    padding:15px 25px;
    border-left:4px solid transparent;
}

.sidebar ul li:hover,
.sidebar ul li.active{
    background:#f0f6ff;
    border-left:4px solid #0f4c81;
}

.sidebar ul li a{
    text-decoration:none;
    color:#333;
    display:block;
}

.main-content{
    flex:1;
    padding:30px;
}

.schedule-header{
    margin-bottom:25px;
}

.schedule-grid{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:15px;
}

.day-card{
    background:#fff;
    border-radius:12px;
    padding:20px;
    box-shadow:0 8px 20px rgba(0,0,0,.05);
    min-height:220px;
    border-top:5px solid #0f4c81;
}

.day-title{
    font-size:16px;
    font-weight:700;
    margin-bottom:10px;
    color:#0f4c81;
}

.day-date{
    font-size:13px;
    color:#777;
    margin-bottom:20px;
}

.shift-card{
    background:#eef5ff;
    border-radius:10px;
    padding:12px;
    margin-bottom:10px;
}

.shift-name{
    font-weight:700;
    color:#0f4c81;
    margin-bottom:5px;
}

.no-shift{
    background:#f8f9fa;
    border:1px dashed #ccc;
    padding:15px;
    border-radius:10px;
    text-align:center;
    color:#888;
    margin-top:30px;
}

.today-badge{
    background:#28a745;
    color:#fff;
    font-size:11px;
    padding:4px 10px;
    border-radius:20px;
    display:inline-block;
    margin-top:10px;
}

.week-info{
    background:#fff;
    padding:20px;
    border-radius:12px;
    margin-bottom:25px;
    box-shadow:0 8px 20px rgba(0,0,0,.05);
}

@media(max-width:1200px){

    .schedule-grid{
        grid-template-columns:repeat(3,1fr);
    }
}

@media(max-width:900px){

    .schedule-grid{
        grid-template-columns:repeat(2,1fr);
    }
}

@media(max-width:700px){

    .container{
        flex-direction:column;
    }

    .sidebar{
        width:100%;
    }

    .schedule-grid{
        grid-template-columns:1fr;
    }
}

</style>
</head>

<body>

<header>

<div>

<h2>
    MediPulse
</h2>

</div>

<div>

Logged in as

<strong>

<?= htmlspecialchars($doctorName) ?>

</strong>

</div>

</header>

<div class="container">

<nav class="sidebar">

<ul>

<li>
    <a href="doctor-dash.php">
        <i class="fa-solid fa-gauge"></i>
        Dashboard
    </a>
</li>

<li class="active">
    <a href="doctor-schedule-view.php">
        <i class="fa-solid fa-calendar-days"></i>
        My Schedule
    </a>
</li>

</ul>

</nav>

<main class="main-content">

<div class="schedule-header">

<h2>
    My Weekly Schedule
</h2>

<p>

View your assigned shifts for this week.

</p>

</div>

<div class="week-info">

<strong>

Week Period:

</strong>

<?= date('d M Y', strtotime($monday)) ?>

-

<?= date('d M Y', strtotime($sunday)) ?>

</div>

<div class="schedule-grid">

<?php foreach($weekDays as $day): ?>

<?php

$hasShift = false;

?>

<div class="day-card">

<div class="day-title">

<?= $day['day'] ?>

</div>

<div class="day-date">

<?= date('d M Y', strtotime($day['date'])) ?>

</div>

<?php if($day['date'] == date('Y-m-d')): ?>

<div class="today-badge">

Today

</div>

<?php endif; ?>

<br><br>

<?php foreach($schedules as $schedule): ?>

<?php if($schedule['schedule_date'] == $day['date']): ?>

<?php $hasShift = true; ?>

<div class="shift-card">

<div class="shift-name">

<?= htmlspecialchars($schedule['shift_type']) ?>

Shift

</div>

<div>

Assigned Duty

</div>

</div>

<?php endif; ?>

<?php endforeach; ?>

<?php if(!$hasShift): ?>

<div class="no-shift">

No Shift Assigned

</div>

<?php endif; ?>

</div>

<?php endforeach; ?>

</div>

</main>

</div>

</body>
</html>