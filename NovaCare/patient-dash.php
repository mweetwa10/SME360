<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header("Location: patient-login.php");
    exit;
}

session_regenerate_id(true);

$patient_id = $_SESSION['user_id'];
$patientName = $_SESSION['full_name'];

$appointments = [];
$stmt = $conn->prepare("
    SELECT scheduled_date, status 
    FROM appointments 
    WHERE patient_id = ?
    ORDER BY scheduled_date DESC
");
$stmt->bind_param("s", $patient_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}
$stmt->close();

$history = [];
$stmt = $conn->prepare("
    SELECT 
        p.created_at,
        d.full_name AS doctor_name,
        p.diagnosis,
        m.name,
        p.dosage,
        p.frequency,
        p.duration,
        p.instructions
    FROM prescriptions p
    JOIN doctors d ON p.doctor_id = d.doctor_id
    JOIN medicines m ON p.medicine_id = m.medicine_id
    WHERE p.patient_id = ?
    ORDER BY p.created_at DESC
");
$stmt->bind_param("s", $patient_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();

$labResults = [];
$stmt = $conn->prepare("
    SELECT 
        lr.result,
        lr.result_date,
        lreq.test_name
    FROM lab_results lr
    JOIN lab_requests lreq 
        ON lr.lab_request_id = lreq.lab_request_id
    WHERE lreq.patient_id = ?
    ORDER BY lr.result_date DESC
");
$stmt->bind_param("s", $patient_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $labResults[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Patient Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Tahoma,Verdana,sans-serif}
body{background:#f4f7fb;color:#333}
header{background:#0f4c81;color:#fff;padding:15px 30px;display:flex;justify-content:space-between;align-items:center}
.container{display:flex;min-height:calc(100vh - 70px)}
.sidebar{width:240px;background:#fff;box-shadow:2px 0 10px rgba(0,0,0,.05);padding-top:20px}
.sidebar ul{list-style:none}
.sidebar ul li{padding:15px 25px;border-left:4px solid transparent;cursor:pointer}
.sidebar ul li:hover,.active{background:#f0f6ff;border-left:4px solid #0f4c81;font-weight:600}
.sidebar ul li a{text-decoration:none;color:inherit}
.main-content{flex:1;padding:30px}
.card{background:#fff;padding:25px;border-radius:10px;margin-bottom:25px;box-shadow:0 8px 20px rgba(0,0,0,.05)}
.card h3{margin-bottom:15px;color:#0f4c81}
table{width:100%;border-collapse:collapse}
th,td{padding:12px;font-size:14px;border-bottom:1px solid #eee;text-align:left}
th{background:#0f4c81;color:#fff}
button{background:#1fa2ff;color:#fff;border:none;padding:8px 14px;border-radius:5px;cursor:pointer}
button:hover{background:#1685d9}
input{width:100%;padding:8px;border-radius:6px;border:1px solid #ccc}
.empty{text-align:center;padding:15px;color:#888}
</style>
</head>
<body>

<header>
    <h2>MediPulse</h2>
    <div>Logged in as <strong><?= htmlspecialchars($patientName) ?></strong></div>
</header>

<div class="container">

<nav class="sidebar">
<ul>
    <li class="active"><a href="patient-dash.php">Dashboard</a></li>
    <li><a href="appointment.php">Appointment</a></li>
     <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
    <li><a href="patient-login.php">Logout</a></li>
</ul>
</nav>

<main class="main-content">

<div class="card">
<h3>My Appointments</h3>
<table>
<tr>
<th>Date</th>
<th>Status</th>
</tr>

<?php if(count($appointments) > 0): ?>
<?php foreach($appointments as $app): ?>
<tr>
<td>
<?= !empty($app['scheduled_date']) 
    ? date("d M Y", strtotime($app['scheduled_date'])) 
    : 'Not Scheduled'; ?>
</td>
<td><?= htmlspecialchars($app['status']) ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="2" class="empty">No appointments found.</td></tr>
<?php endif; ?>

</table>
</div>


<div class="card">
<h3>Medical History</h3>
<table>
<tr>
<th>Date</th>
<th>Doctor</th>
<th>Diagnosis</th>
<th>Medicine</th>
<th>Dosage</th>
<th>Instructions</th>
</tr>

<?php if(count($history) > 0): ?>
<?php foreach($history as $h): ?>
<tr>
<td><?= date("d M Y", strtotime($h['created_at'])) ?></td>
<td><?= htmlspecialchars($h['doctor_name']) ?></td>
<td><?= htmlspecialchars($h['diagnosis']) ?></td>
<td><?= htmlspecialchars($h['name']) ?></td>
<td>
<?= htmlspecialchars($h['dosage']) ?><br>
<?= htmlspecialchars($h['frequency']) ?> for <?= htmlspecialchars($h['duration']) ?>
</td>
<td><?= htmlspecialchars($h['instructions']) ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="6" class="empty">No medical history available.</td></tr>
<?php endif; ?>

</table>
</div>


<div class="card">
<h3>Lab Results</h3>
<table>
<tr>
<th>Test</th>
<th>Result</th>
<th>Date</th>
</tr>
<?php if(count($labResults) > 0): ?>
<?php foreach($labResults as $lab): ?>
<tr>
<td><?= htmlspecialchars($lab['test_name']) ?></td>
<td><?= htmlspecialchars($lab['result']) ?></td>
<td><?= date("d M Y", strtotime($lab['result_date'])) ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="3" class="empty">No lab results available.</td></tr>
<?php endif; ?>
</table>
</div>

</main>
</div>

</body>
</html>
