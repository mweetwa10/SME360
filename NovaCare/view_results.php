<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Lab Staff') {
    header("Location: login.php");
    exit;
}

$staff_id = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT 
        lr.result,
        lr.result_date,
        p.full_name
    FROM lab_results lr
    INNER JOIN lab_requests lq ON lq.lab_request_id = lr.lab_request_id
    INNER JOIN patients p ON p.patient_id = lq.patient_id
    WHERE lr.lab_staff_id = ?
    ORDER BY lr.result_date DESC
");

$stmt->bind_param("s", $staff_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Lab Results</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:"Segoe UI",Tahoma,Verdana,sans-serif;
}

body{background:#f4f7fb;color:#333;}

header{
    background:#0f4c81;
    color:#fff;
    padding:15px 30px;
    display:flex;
    justify-content:space-between;
}

.container{display:flex;min-height:100vh;}

.sidebar{
    width:240px;
    background:#fff;
    box-shadow:2px 0 10px rgba(0,0,0,0.05);
    padding-top:20px;
}

.sidebar ul{list-style:none;}

.sidebar ul li{padding:14px 22px;}

.sidebar ul li a{
    text-decoration:none;
    color:#333;
    display:flex;
    gap:10px;
}

.sidebar ul li:hover{background:#f0f6ff;}

.sidebar ul li.active{
    background:#eaf3ff;
    border-left:4px solid #0f4c81;
}

.main-content{flex:1;padding:30px;}

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
}

th,td{
    padding:12px;
    border-bottom:1px solid #eee;
    font-size:14px;
}

th{
    background:#0f4c81;
    color:#fff;
    text-align:left;
}
</style>
</head>

<body>

<header>
    <h2>MediPulse</h2>
</header>

<div class="container">

<nav class="sidebar">
<ul>
    <li class="active">
        <a href="view_results.php">
            <i class="fas fa-vials"></i> Lab Results
        </a>
    </li>

    <li>
        <a href="lab-dash.php">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
    </li>
</ul>
</nav>

<div class="main-content">

<div class="card">
<h3 class="section-title">Patient Lab Results</h3>

<table>
<tr>
    <th>Patient</th>
    <th>Result</th>
    <th>Date</th>
</tr>

<?php while($row = $result->fetch_assoc()): ?>
<tr>
    <td><?= htmlspecialchars($row['full_name']) ?></td>
    <td><?= htmlspecialchars($row['result']) ?></td>
    <td><?= $row['result_date'] ?></td>
</tr>
<?php endwhile; ?>

</table>

</div>

</div>
</div>

</body>
</html>