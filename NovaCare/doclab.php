<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    $_SESSION['error'] = "You must be logged in as a doctor.";
    header("Location: login.php");
    exit;
}

$doctor_id = (int) $_SESSION['user_id'];

$doctorName = "Doctor";
$stmt = $conn->prepare("SELECT full_name FROM doctors WHERE doctor_id = ?");
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$stmt->bind_result($doctorName);
$stmt->fetch();
$stmt->close();

$labRequests = [];
$sql = "
    SELECT lr.lab_request_id, lr.patient_id, p.full_name AS patient_name, lr.test_name, lr.status, lr.clinical_notes, lr.requested_at
    FROM lab_requests lr
    JOIN patients p ON lr.patient_id = p.patient_id
    WHERE lr.doctor_id = ?
    ORDER BY lr.requested_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $labRequests[] = $row;
}
$stmt->close();

$labResults = [];
$sql = "
    SELECT res.lab_result_id, res.lab_request_id, res.lab_staff_id, res.result, res.result_date
    FROM lab_results res
    JOIN lab_requests lr ON res.lab_request_id = lr.lab_request_id
    WHERE lr.doctor_id = ?
    ORDER BY res.result_date DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $labResults[$row['lab_request_id']] = $row; 
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Lab Requests & Results</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Tahoma,Verdana,sans-serif}
body{background:#f4f7fb;color:#333}
header{background:#0f4c81;color:#fff;padding:15px 30px;display:flex;justify-content:space-between;align-items:center}
.header-left{display:flex;align-items:center;gap:12px}
.header-left img{width:40px}
.container{display:flex;min-height:calc(100vh - 70px)}
.sidebar{width:240px;background:#fff;box-shadow:2px 0 10px rgba(0,0,0,.05);padding-top:20px}
.sidebar ul{list-style:none}
.sidebar ul li{padding:15px 25px;font-size:14px;cursor:pointer;border-left:4px solid transparent;transition:.3s}
.sidebar ul li:hover,.sidebar ul li.active{background:#f0f6ff;border-left:4px solid #0f4c81;color:#0f4c81;font-weight:600}
.sidebar ul li a{text-decoration:none;color:inherit;display:block;width:100%}
.main-content{flex:1;padding:30px}
.table-section{margin-top:35px}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 8px 20px rgba(0,0,0,.05)}
th,td{padding:14px;font-size:14px;border-bottom:1px solid #eee}
th{background:#0f4c81;color:#fff}
tr:hover{background:#f9fbff}
.status{font-weight:600;color:#e67e22}
.action-btn,.submit-btn{background:#1fa2ff;color:#fff;border:none;padding:8px 14px;border-radius:5px;font-size:13px;cursor:pointer}
.action-btn:hover,.submit-btn:hover{background:#1685d9}
.consultation-card{display:none;margin-top:35px;background:#fff;padding:25px;border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,.05)}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px}
.form-group label{font-size:13px;margin-bottom:5px;display:block}
.form-group input,.form-group textarea,.form-group select{width:100%;padding:10px;border-radius:6px;border:1px solid #ccc;font-size:14px}
textarea{resize:none}
.section-title{margin-bottom:15px;font-weight:600;color:#0f4c81}
</style>
</head>
<body>

<header>
<div class="header-left">
<img src="logo1.png">
<h1>MediPulse</h1>
</div>
<div>Logged in as <strong><?= htmlspecialchars($doctorName) ?></strong></div>
</header>

<div class="container">
<nav class="sidebar">
<ul>
<li class="active"><a href="lab-dashboard.php"><i class="fas fa-vials"></i> Lab Requests & Results</a></li>
<li><a href="doctor-dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
<li><a href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
</ul>
</nav>

<main class="main-content">

<div class="consultation-card" id="labForm">
<h3 class="section-title">New Lab Request</h3>
<div class="form-grid">
<div class="form-group">
<label>Patient ID</label>
<input type="text" id="patientId" placeholder="Enter Patient ID">
</div>
<div class="form-group">
<label>Lab Test</label>
<select id="labTest">
<option value="">-- Select Test --</option>
<option>Full Blood Count</option>
<option>Malaria Test</option>
<option>Urinalysis</option>
<option>Liver Function Test</option>
<option>COVID-19 Test</option>
</select>
</div>
<div class="form-group">
<label>Clinical Notes</label>
<textarea id="labNotes"></textarea>
</div>
</div>
<button class="submit-btn" id="requestLabBtn" type="button">Request Lab Test</button>
</div>

<div class="table-section">
<h3>Lab Requests</h3>
<table id="labRequestsTable">
<thead>
<tr>
<th>ID</th>
<th>Patient ID</th>
<th>Patient Name</th>
<th>Test Name</th>
<th>Status</th>
<th>Notes</th>
<th>Requested At</th>
<th>Result</th>
<th>Result Date</th>
</tr>
</thead>
<tbody>
<?php foreach($labRequests as $lr): ?>
<tr>
<td><?= $lr['lab_request_id'] ?></td>
<td><?= $lr['patient_id'] ?></td>
<td><?= htmlspecialchars($lr['patient_name']) ?></td>
<td><?= htmlspecialchars($lr['test_name']) ?></td>
<td class="status"><?= htmlspecialchars($lr['status']) ?></td>
<td><?= htmlspecialchars($lr['notes']) ?></td>
<td><?= date("Y-m-d H:i", strtotime($lr['requested_at'])) ?></td>
<td><?= isset($labResults[$lr['lab_request_id']]) ? htmlspecialchars($labResults[$lr['lab_request_id']]['result']) : '-' ?></td>
<td><?= isset($labResults[$lr['lab_request_id']]) ? date("Y-m-d", strtotime($labResults[$lr['lab_request_id']]['result_date'])) : '-' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</main>
</div>

<script>
const patientId = document.getElementById('patientId');
const labTest = document.getElementById('labTest');
const labNotes = document.getElementById('labNotes');
const requestLabBtn = document.getElementById('requestLabBtn');

requestLabBtn.addEventListener('click', function () {
    if(!patientId.value.trim()){
        alert("Please enter Patient ID");
        return;
    }
    if(!labTest.value.trim()){
        alert("Please select a lab test");
        return;
    }

    const formData = new URLSearchParams();
    formData.append("patient_id", patientId.value.trim());
    formData.append("test_name", labTest.value.trim());
    formData.append("notes", labNotes.value.trim());

    fetch('save-lab-request.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: formData.toString()
    })
    .then(res => res.json())
    .then(data => {
        if(data.success){
            alert("Lab request sent successfully");
            location.reload();
        } else {
            alert("Error: " + data.error);
        }
    })
    .catch(err=>{
        console.error(err);
        alert("Network error");
    });
});
</script>

</body>
</html>