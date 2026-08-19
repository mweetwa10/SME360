<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Nurse') {
    $_SESSION['error'] = "Access denied. Nurses only.";
    header("Location: login.php");
    exit;
}

$nurse_id = $_SESSION['user_id'];
$nurseName = "Nurse";

$stmt = $conn->prepare("
    SELECT full_name 
    FROM nurses 
    WHERE nurse_id = ?
");

$stmt->bind_param("s", $nurse_id);
$stmt->execute();
$stmt->bind_result($nurseName);
$stmt->fetch();
$stmt->close();

$sql = "
SELECT 
    a.*,
    p.full_name
FROM admissions a
JOIN patients p 
ON p.patient_id = a.patient_id
ORDER BY a.admitted_at DESC
";

$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Ward Dashboard</title>

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
    padding:15px 30px;
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
    box-shadow:2px 0 10px rgba(0,0,0,0.05);
    padding-top:20px;
}

.sidebar ul{
    list-style:none;
}

.sidebar ul li{
    padding:14px 22px;
    border-left:4px solid transparent;
    transition:all 0.25s ease;
}

.sidebar ul li a{
    text-decoration:none;
    color:#333;
    display:flex;
    align-items:center;
    gap:10px;
}

.sidebar ul li:hover{
    background:#f0f6ff;
}

.sidebar ul li:hover a{
    color:#0f4c81;
}

.sidebar ul li.active{
    background:#eaf3ff;
    border-left:4px solid #0f4c81;
}

.sidebar ul li.active a{
    color:#0f4c81;
    font-weight:600;
}

.sidebar i{
    color:#0f4c81;
}

.main-content{
    flex:1;
    padding:30px;
}

.card{
    background:#fff;
    padding:25px;
    border-radius:12px;
    box-shadow:0 8px 20px rgba(0,0,0,0.05);
}

.section-title{
    color:#0f4c81;
    margin-bottom:15px;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:15px;
    background:#fff;
    border-radius:10px;
    overflow:hidden;
    box-shadow:0 8px 20px rgba(0,0,0,0.05);
}

th, td{
    padding:12px;
    font-size:14px;
    border-bottom:1px solid #eee;
}

th{
    background:#0f4c81;
    color:#fff;
}

tr:hover{
    background:#f9fbff;
}

.admitted{
    color:#166534;
    font-weight:600;
}

.discharged{
    color:#991b1b;
    font-weight:600;
}
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>

<header>
    <div class="header-left">
        <h1>MediPulse</h1>
    </div>
    <div class="header-right">
        Logged in as: <strong><?= htmlspecialchars($nurseName) ?></strong>
    </div>
</header>

<div class="container">

<nav class="sidebar">
<ul>
    <li>
        <a href="nurse-dash.php">
            <i class="fas fa-tachometer-alt"></i>
            Dashboard
        </a>
    </li>

    <li class="active">
        <a href="ward.php">
            <i class="fas fa-procedures"></i>
            Ward Management
        </a>
    </li>
</nav>

<div class="main-content">

<div class="card">
    <h3 class="section-title">Ward Admissions</h3>

    <table>
        <thead>
            <tr>
                <th>Patient</th>
                <th>Ward</th>
                <th>Room</th>
                <th>Reason</th>
                <th>Admitted At</th>
                <th>Discharged At</th>
                <th>Status</th>
            </tr>
        </thead>

        <tbody>
        <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($row['full_name']) ?></td>
                <td><?= htmlspecialchars($row['ward_name']) ?></td>
                <td><?= htmlspecialchars($row['room_number']) ?></td>
                <td><?= htmlspecialchars($row['reason']) ?></td>
                <td><?= htmlspecialchars($row['admitted_at']) ?></td>
                <td><?= htmlspecialchars($row['discharged_at'] ?? '-') ?></td>
                <td>
                    <?php if($row['status'] === 'Admitted'): ?>
                        <span class="admitted">Admitted</span>
                    <?php else: ?>
                        <span class="discharged">Discharged</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>

</div>

</div>
</div>

</body>
</html>