<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header("Location: login.php");
    exit;
}

$doctor_id = $_SESSION['user_id'];

$doctorQuery = mysqli_query($conn,"
    SELECT full_name, phone
    FROM doctors
    WHERE doctor_id='$doctor_id'
");

$doctor = mysqli_fetch_assoc($doctorQuery);

$doctor_name = $doctor['full_name'];
$doctor_phone = $doctor['phone'];

$activeReferrals = mysqli_query($conn,"
    SELECT
        r.referral_id,
        r.referred_hospital,
        r.referral_date,
        p.patient_id,
        p.full_name,
        p.nrc
    FROM referrals r
    INNER JOIN patients p
        ON r.patient_id = p.patient_id
    WHERE r.returned_status='Not Returned'
    ORDER BY r.referral_date DESC
");
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Patient Referral</title>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

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
min-height:calc(100vh - 70px);
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
}

.sidebar ul li.active{
background:#f0f6ff;
border-left:4px solid #0f4c81;
}

.sidebar ul li a{
text-decoration:none;
color:#333;
}

.main-content{
flex:1;
padding:30px;
}

.card{
background:#fff;
padding:25px;
border-radius:12px;
margin-bottom:20px;
box-shadow:0 8px 20px rgba(0,0,0,.05);
}

.section-title{
color:#0f4c81;
margin-bottom:20px;
}

.form-grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
gap:15px;
}

label{
display:block;
margin-bottom:6px;
font-weight:600;
}

input,
select,
textarea{
width:100%;
padding:10px;
border:1px solid #ccc;
border-radius:6px;
}

textarea{
resize:none;
}

.btn{
border:none;
padding:10px 15px;
border-radius:6px;
cursor:pointer;
color:white;
font-weight:600;
}

.search-btn{
background:#0f4c81;
}

.generate-btn{
background:#16a34a;
}

.print-btn{
background:#1fa2ff;
}

.return-btn{
background:#dc2626;
}

.patient-box{
display:none;
margin-top:20px;
padding:15px;
background:#eef7ff;
border-radius:8px;
}

.print-area{
display:none;
}

table{
width:100%;
border-collapse:collapse;
}

table th{
background:#0f4c81;
color:white;
padding:10px;
}

table td{
padding:10px;
border-bottom:1px solid #ddd;
}

@media print{

header,
.sidebar,
.referral-form,
.active-referrals{
display:none;
}

.print-area{
display:block !important;
}

}

</style>
</head>

<body>

<header>
<h2>MediPulse</h2>
<div><?= htmlspecialchars($doctor_name) ?></div>
</header>

<div class="container">

<nav class="sidebar">
<ul>

<li>
<a href="doctor-dash.php">
<i class="fas fa-home"></i>
Dashboard
</a>
</li>

<li class="active">
<a href="referral.php">
<i class="fas fa-file-medical"></i>
Patient Referrals
</a>
</li>

</ul>
</nav>

<div class="main-content">

<div class="card referral-form">

<h2 class="section-title">
Patient Referral Form
</h2>

<input type="hidden" id="patient_id">

<div class="form-grid">

<div>
<label>Patient ID or NRC</label>

<input
type="text"
id="search_value"
placeholder="Enter Patient ID or NRC">

</div>

<div>

<label>&nbsp;</label>

<button
type="button"
class="btn search-btn"
onclick="searchPatient()">

Search Patient

</button>

</div>

</div>

<div
class="patient-box"
id="patientBox">

<h3 id="patient_name"></h3>

<br>

<p>
<strong>Patient ID:</strong>
<span id="display_id"></span>
</p>

<p>
<strong>NRC:</strong>
<span id="display_nrc"></span>
</p>

<p>
<strong>Phone:</strong>
<span id="display_phone"></span>
</p>

</div>
<br>

<div class="form-grid">

<div>

<label>Receiving Hospital</label>

<select id="hospital">

<option value="">
Select Hospital
</option>

<option>
University Teaching Hospital (UTH)
</option>

<option>
Levy Mwanawasa University Teaching Hospital
</option>

<option>
Ndola Central Hospital
</option>

<option>
Kitwe Teaching Hospital
</option>

<option>
Cancer Diseases Hospital
</option>

<option>
Arthur Davison Children's Hospital
</option>

</select>

</div>

<div>

<label>Urgency</label>

<select id="urgency">

<option>Routine</option>
<option>Urgent</option>
<option>Emergency</option>

</select>

</div>

</div>

<br>

<label>Diagnosis</label>

<textarea
id="diagnosis"
rows="4"></textarea>

<br><br>

<label>Treatment Given</label>

<textarea
id="treatment"
rows="4"></textarea>

<br><br>

<label>Reason For Referral</label>

<textarea
id="reason"
rows="4"></textarea>

<br><br>

<label>Additional Notes</label>

<textarea
id="notes"
rows="4"></textarea>

<br><br>

<button
type="button"
class="btn generate-btn"
onclick="generateReferral()">

Generate Referral

</button>

<button
type="button"
class="btn print-btn"
onclick="printReferral()">

Print Referral

</button>

</div>

<div
class="card print-area"
id="printLetter">

<h1 style="text-align:center;">
MEDIPULSE HMS
</h1>

<p style="text-align:center;">
P.O Box 1343, Lusaka, Zambia
</p>

<hr>

<h2 style="text-align:center;">
PATIENT REFERRAL LETTER
</h2>

<br>

<p>

<strong>Date:</strong>
<span id="pDate"></span>

</p>

<br>

<p>

To:<br>

<strong id="pHospital"></strong>

</p>

<br>

<p>

Dear Sir/Madam,

</p>

<p>

We kindly refer the following patient
to your institution for further
assessment and management.

</p>

<br>

<p>

<strong>Patient Name:</strong>
<span id="pName"></span>

<br>

<strong>NRC:</strong>
<span id="pNRC"></span>

<br>

<strong>Phone:</strong>
<span id="pPhone"></span>

<br>

<strong>Urgency:</strong>
<span id="pUrgency"></span>

</p>

<br>

<p>
<strong>Diagnosis</strong>
</p>

<p id="pDiagnosis"></p>

<br>

<p>
<strong>Treatment Administered</strong>
</p>

<p id="pTreatment"></p>

<br>

<p>
<strong>Reason For Referral</strong>
</p>

<p id="pReason"></p>

<br>

<p>
<strong>Additional Notes</strong>
</p>

<p id="pNotes"></p>

<br>

<hr>

<p>

<strong>Referring Doctor:</strong>

Dr.
<?= htmlspecialchars($doctor_name); ?>

<br>

<strong>Contact:</strong>

<?= htmlspecialchars($doctor_phone); ?>

</p>

<br><br>

<p>

Yours Faithfully,

</p>

<br><br><br>

<p>

_____________________________

<br>

<strong>

Dr.
<?= htmlspecialchars($doctor_name); ?>

</strong>

<br>

Attending Doctor

</p>

</div>

<div class="card active-referrals">

<h2 class="section-title">
Active Referrals
</h2>

<table>

<tr>
<th>Patient ID</th>
<th>Name</th>
<th>NRC</th>
<th>Hospital</th>
<th>Date</th>
<th>Action</th>
</tr>

<?php while($r = mysqli_fetch_assoc($activeReferrals)): ?>

<tr>

<td><?= $r['patient_id']; ?></td>
<td><?= htmlspecialchars($r['full_name']); ?></td>
<td><?= htmlspecialchars($r['nrc']); ?></td>
<td><?= htmlspecialchars($r['referred_hospital']); ?></td>
<td><?= $r['referral_date']; ?></td>

<td>

<button
class="btn return-btn"
onclick="markReturned(<?= $r['referral_id']; ?>)">

Mark Returned

</button>

</td>

</tr>

<?php endwhile; ?>

</table>

</div>

</div>
</div>

<script>

function searchPatient(){

let value = document.getElementById('search_value').value.trim();

if(value === ''){
alert('Enter Patient ID or NRC');
return;
}

fetch('find-patient.php', {
method: 'POST',
headers: {
'Content-Type': 'application/x-www-form-urlencoded'
},
body: 'search=' + encodeURIComponent(value)
})

.then(res => res.json())
.then(data => {

if(data.status === 'not_found'){
alert('Patient not found');
return;
}

if(data.status === 'blocked'){
alert(data.message);
return;
}

document.getElementById('patient_id').value = data.patient.patient_id;

document.getElementById('patient_name').innerHTML = data.patient.full_name;

document.getElementById('display_id').innerHTML = data.patient.patient_id;

document.getElementById('display_nrc').innerHTML = data.patient.nrc;

document.getElementById('display_phone').innerHTML = data.patient.phone;

document.getElementById('patientBox').style.display = 'block';

});

}

function generateReferral(){

let patient_id = document.getElementById('patient_id').value;

if(patient_id === ''){
alert('Search patient first');
return;
}

let formData = new FormData();

formData.append('patient_id', patient_id);
formData.append('hospital', document.getElementById('hospital').value);
formData.append('urgency', document.getElementById('urgency').value);
formData.append('diagnosis', document.getElementById('diagnosis').value);
formData.append('treatment', document.getElementById('treatment').value);
formData.append('reason', document.getElementById('reason').value);
formData.append('notes', document.getElementById('notes').value);

fetch('save-referral.php', {
method: 'POST',
body: formData
})

.then(res => res.json())
.then(data => {

if(data.status !== 'success'){
alert(data.message);
return;
}

document.getElementById('pDate').innerText = new Date().toLocaleDateString();
document.getElementById('pHospital').innerText = document.getElementById('hospital').value;
document.getElementById('pName').innerText = document.getElementById('patient_name').innerText;
document.getElementById('pNRC').innerText = document.getElementById('display_nrc').innerText;
document.getElementById('pPhone').innerText = document.getElementById('display_phone').innerText;
document.getElementById('pUrgency').innerText = document.getElementById('urgency').value;
document.getElementById('pDiagnosis').innerText = document.getElementById('diagnosis').value;
document.getElementById('pTreatment').innerText = document.getElementById('treatment').value;
document.getElementById('pReason').innerText = document.getElementById('reason').value;
document.getElementById('pNotes').innerText = document.getElementById('notes').value;

document.getElementById('printLetter').style.display = 'block';

alert('Referral saved successfully');

});

}

function printReferral(){
window.print();
}

function markReturned(id){

if(!confirm('Mark patient as returned?')) return;

fetch('mark-returned.php', {
method: 'POST',
headers: {
'Content-Type': 'application/x-www-form-urlencoded'
},
body: 'referral_id=' + id
})

.then(res => res.json())
.then(data => {
alert(data.message);
if(data.status === 'success'){
location.reload();
}
});

}

</script>

</body>
</html>



