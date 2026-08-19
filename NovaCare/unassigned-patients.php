<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header("Location: login.php");
    exit;
}

$doctor_id = $_SESSION['user_id'];

$patients = [];

$sql = "
    SELECT dq.queue_id, p.patient_id, p.full_name, dq.assigned_at
    FROM doctor_queue dq
    JOIN patients p ON dq.patient_id = p.patient_id
    WHERE dq.doctor_id IS NULL
    AND dq.status = 'Waiting'
    ORDER BY dq.assigned_at IS NULL DESC, dq.assigned_at ASC
";

$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $patients[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Unassigned Patients</title>

<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Tahoma}
body{background:#f4f7fb;color:#333}

header{
    background:#0f4c81;
    color:#fff;
    padding:15px 25px;
    display:flex;
    justify-content:space-between;
}

.container{display:flex}
.sidebar{
    width:220px;
    background:#fff;
    min-height:100vh;
    box-shadow:2px 0 10px rgba(0,0,0,.05);
}
.sidebar a{
    display:block;
    padding:15px 20px;
    text-decoration:none;
    color:#333;
}
.sidebar a:hover{
    background:#f0f6ff;
    color:#0f4c81;
}

.main{
    flex:1;
    padding:30px;
}

.card{
    background:#fff;
    padding:25px;
    border-radius:10px;
    box-shadow:0 8px 20px rgba(0,0,0,.05);
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}
th,td{
    padding:14px;
    border-bottom:1px solid #eee;
}
th{
    background:#0f4c81;
    color:#fff;
}
tr:hover{background:#f9fbff}

.assign-btn{
    background:#1fa2ff;
    color:#fff;
    border:none;
    padding:8px 14px;
    border-radius:5px;
    cursor:pointer;
}
.assign-btn:hover{background:#1685d9}

.back-btn{
    display:inline-block;
    margin-bottom:15px;
    background:#555;
    color:#fff;
    padding:8px 14px;
    border-radius:5px;
    text-decoration:none;
}
.back-btn:hover{background:#333}

.time-badge{
    padding:5px 10px;
    border-radius:5px;
    background:#bdc3c7;
    color:#fff;
    font-size:12px;
}
</style>
</head>

<body>

<header>
<h2>MediPulse</h2>
</header>

<div class="container">


<div class="main">

<a href="doctor-dash.php" class="back-btn">← Back to Dashboard</a>

<div class="card">

<h3>Unassigned Patients</h3>

<table>
<thead>
<tr>
<th>Patient ID</th>
<th>Name</th>
<th>Assigned Time</th>
<th>Action</th>
</tr>
</thead>

<tbody>
<?php foreach ($patients as $row): ?>
<tr data-id="<?= $row['queue_id'] ?>">

<td><?= $row['patient_id'] ?></td>

<td><?= htmlspecialchars($row['full_name']) ?></td>

<td>
<?php if ($row['assigned_at']): ?>
<span class="time-badge">
<?= date("H:i", strtotime($row['assigned_at'])) ?>
</span>
<?php else: ?>
<span class="time-badge">Pending</span>
<?php endif; ?>
</td>

<td>
<button class="assign-btn">Assign to Me</button>
</td>

</tr>
<?php endforeach; ?>
</tbody>

</table>

</div>
</div>
</div>

<script>
document.querySelectorAll('.assign-btn').forEach(btn => {
    btn.onclick = () => {
        const row = btn.closest('tr');
        const queueId = row.dataset.id;

        fetch('assign-doctor.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body:`queue_id=${queueId}`
        })
        .then(res=>res.json())
        .then(data=>{
            if(data.success){
                row.remove();
            }else{
                alert(data.error || "Assignment failed");
            }
        });
    };
});
</script>

</body>
</html>