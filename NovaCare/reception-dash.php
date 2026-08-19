<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role']!=='Receptionist'){
    header('Location: login.php');
    exit;
}

$stmt=$conn->prepare("SELECT COUNT(*) FROM patients WHERE DATE(created_at)=CURDATE()");
$stmt->execute();
$stmt->bind_result($patientsToday);
$stmt->fetch();
$stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(scheduled_date)=CURDATE()");
$stmt->execute();
$stmt->bind_result($appointmentsToday);
$stmt->fetch();
$stmt->close();

$stmt=$conn->prepare("SELECT COUNT(*) FROM reception_queue WHERE status='Waiting'");
$stmt->execute();
$stmt->bind_result($waitingForNurse);
$stmt->fetch();
$stmt->close();

$printCard=false;
$cardName='';
$cardId='';

if(isset($_SESSION['registered_patient_name']) && isset($_SESSION['registered_patient_id'])){
    $printCard=true;
    $cardName=$_SESSION['registered_patient_name'];
    $cardId=$_SESSION['registered_patient_id'];

    unset($_SESSION['registered_patient_name']);
    unset($_SESSION['registered_patient_id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Receptionist Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

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
width:250px;
background:#fff;
box-shadow:2px 0 10px rgba(0,0,0,.05);
padding-top:20px;
}

.sidebar ul{
list-style:none;
}

.sidebar ul li{
font-size:14px;
border-left:4px solid transparent;
}

.sidebar ul li a{
display:flex;
align-items:center;
gap:10px;
padding:15px 25px;
text-decoration:none;
color:inherit;
}

.sidebar ul li.active a{
background:#f0f6ff;
border-left:4px solid #0f4c81;
font-weight:600;
}

.sidebar ul li a:hover{
background:#f0f6ff;
}

.main-content{
flex:1;
padding:30px;
}

.welcome{
margin-bottom:25px;
}

.welcome p{
color:#666;
}

.cards{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
gap:20px;
margin-bottom:30px;
}

.card{
background:#fff;
padding:20px;
border-radius:10px;
box-shadow:0 8px 20px rgba(0,0,0,.05);
}

.card h3{
margin-bottom:10px;
color:#666;
font-size:15px;
}

.card span{
font-size:26px;
font-weight:700;
color:#0f4c81;
}

.form-card{
background:#fff;
padding:25px;
border-radius:10px;
box-shadow:0 8px 20px rgba(0,0,0,.05);
margin-bottom:30px;
}

.form-card h3{
margin-bottom:20px;
color:#0f4c81;
}

.form-grid{
display:grid;
grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
gap:18px;
}

.form-group label{
display:block;
margin-bottom:6px;
font-size:13px;
font-weight:600;
}

.form-group input,
.form-group select{
width:100%;
padding:10px;
border:1px solid #ccc;
border-radius:6px;
font-size:14px;
}

.form-group input:focus,
.form-group select:focus{
outline:none;
border-color:#0f4c81;
}

.btn{
padding:10px 15px;
background:#0f4c81;
color:#fff;
border:none;
border-radius:6px;
cursor:pointer;
font-weight:600;
margin-top:18px;
}

.btn:hover{
background:#0c3b63;
}

.btn:disabled{
background:#9ca3af;
cursor:not-allowed;
}

.alert-success{
background:#e6fffa;
color:#065f46;
padding:12px;
border-radius:6px;
margin-bottom:20px;
}

.alert-error{
background:#ffe6e6;
color:#7f1d1d;
padding:12px;
border-radius:6px;
margin-bottom:20px;
}

.print-card-container{
background:#fff;
padding:25px;
border-radius:10px;
margin-bottom:30px;
box-shadow:0 8px 20px rgba(0,0,0,.05);
}

.patient-card{
width:340px;
height:210px;
border-radius:14px;
background:#0f4c81;
color:#fff;
padding:20px;
position:relative;
overflow:hidden;
}

.patient-card::before{
content:"";
position:absolute;
width:180px;
height:180px;
background:rgba(255,255,255,.08);
border-radius:50%;
top:-40px;
right:-40px;
}

.card-company{
font-size:24px;
font-weight:700;
margin-bottom:25px;
}

.card-label{
font-size:12px;
opacity:.8;
margin-bottom:5px;
}

.card-name{
font-size:22px;
font-weight:700;
margin-bottom:20px;
}

.card-id{
font-size:18px;
font-weight:600;
}

.validation-error{
font-size:12px;
color:#dc2626;
margin-top:4px;
display:none;
}

input.invalid,
select.invalid{
border-color:#dc2626;
}

@media print{
body *{
visibility:hidden;
}
#printCardArea,
#printCardArea *{
visibility:visible;
}
#printCardArea{
position:absolute;
top:0;
left:0;
width:100%;
display:flex;
justify-content:center;
}
}

</style>
</head>
<body>

<header>
<div><h1>MediPulse</h1></div>
<div>
Logged in as:
<strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong>
</div>
</header>

<div class="container">

<nav class="sidebar">
<ul>

<li class="active">
<a href="reception-dash.php">
<i class="fas fa-tachometer-alt"></i> Dashboard
</a>
</li>

<li>
<a href="manage-appointments.php">
<i class="fas fa-calendar-check"></i> Appointments
</a>
</li>

<li>
<a href="profile.php">
<i class="fas fa-user"></i> Profile
</a>
</li>

<li>
<a href="login.php">
<i class="fas fa-sign-out-alt"></i> Logout
</a>
</li>

</ul>
</nav>

<main class="main-content">

<div class="welcome">
<h2>Receptionist Dashboard</h2>
<p>Manage patients and appointments</p>
</div>

<?php if(isset($_SESSION['success'])): ?>
<div class="alert-success">
<?= $_SESSION['success']; unset($_SESSION['success']); ?>
</div>
<?php endif; ?>

<?php if(isset($_SESSION['error'])): ?>
<div class="alert-error">
<?= $_SESSION['error']; unset($_SESSION['error']); ?>
</div>
<?php endif; ?>

<?php if($printCard): ?>
<div class="print-card-container">
<h3 style="margin-bottom:20px;color:#0f4c81;">Patient Card Ready</h3>

<div id="printCardArea">
<div class="patient-card">
<div class="card-company">MediPulse</div>
<div class="card-label">PATIENT NAME</div>
<div class="card-name"><?= htmlspecialchars($cardName) ?></div>
<div class="card-label">PATIENT ID</div>
<div class="card-id"><?= htmlspecialchars($cardId) ?></div>
</div>
</div>

<button onclick="window.print()" class="btn">
<i class="fas fa-print"></i> Print Patient Card
</button>
</div>
<?php endif; ?>

<div class="cards">
<div class="card">
<h3>Patients Today</h3>
<span><?= $patientsToday ?></span>
</div>

<div class="card">
<h3>Appointments Today</h3>
<span><?= $appointmentsToday ?></span>
</div>

<div class="card">
<h3>Waiting for Nurse</h3>
<span><?= $waitingForNurse ?></span>
</div>
</div>

<div class="form-card">

<h3>Register New Patient</h3>

<form method="post" action="register-patient.php" id="patientForm" novalidate>

<div class="form-grid">

<div class="form-group">
<label>Full Name</label>
<input type="text" id="full_name" name="full_name" required>
<div class="validation-error" id="full_name_error">Full name is required.</div>
</div>

<div class="form-group">
<label>Email Address</label>
<input type="email" id="email" name="email" required>
<div class="validation-error" id="email_error">Enter a valid email address.</div>
</div>

<div class="form-group">
<label>Gender</label>
<select id="gender" name="gender" required>
<option value="">Select Gender</option>
<option value="Male">Male</option>
<option value="Female">Female</option>
</select>
<div class="validation-error" id="gender_error">Gender is required.</div>
</div>

<div class="form-group">
<label>Date of Birth</label>
<input
type="date"
id="dob"
name="dob"
max="<?= date('Y-m-d') ?>"
required
>
<div class="validation-error" id="dob_error">Select a valid date of birth.</div>
</div>

<div class="form-group">
<label>Phone Number</label>
<input
type="text"
id="phone"
name="phone"
maxlength="10"
required
>
<div class="validation-error" id="phone_error">Phone number must be exactly 10 digits.</div>
</div>

<div class="form-group">
<label>NRC</label>
<input
type="text"
id="nrc"
name="nrc"
placeholder="xxxxxx/xx/x"
pattern="[0-9]{6}/[0-9]{1,2}/[0-9]{1,2}"
required
>
<div class="validation-error" id="nrc_error">
NRC format must be xxxxxx/x/x or xxxxxx/xx/x
</div>

<div class="form-group">
<label>Next of Kin Full Name</label>
<input
type="text"
id="nok_full_name"
name="nok_full_name"
required
>
<div class="validation-error" id="nok_full_name_error">Next of kin name is required.</div>
</div>

<div class="form-group">
<label>Next of Kin Phone Number</label>
<input
type="text"
id="nok_phone"
name="nok_phone"
maxlength="10"
required
>
<div class="validation-error" id="nok_phone_error">Phone number must be exactly 10 digits.</div>
</div>

</div>

<button
type="submit"
class="btn"
id="registerBtn"
disabled
>
Register Patient
</button>

</form>

</div>

<div class="form-card">

<h3>Check Existing Patient</h3>

<form method="post" action="check-patient.php">

<div class="form-grid">

<div class="form-group">
<label>Patient ID or NRC</label>
<input
type="text"
name="search"
placeholder="PAT2026001 or 243621/10/1"
required
>
</div>

</div>

<button type="submit" class="btn">
Search & Send to Nurse
</button>

</form>

</div>

</main>

</div>

<script>

const registerBtn=document.getElementById('registerBtn');

const fields=[
document.getElementById('full_name'),
document.getElementById('email'),
document.getElementById('gender'),
document.getElementById('dob'),
document.getElementById('phone'),
document.getElementById('nrc'),
document.getElementById('nok_full_name'),
document.getElementById('nok_phone')
];

function showError(id,status){
const el=document.getElementById(id+'_error');
if(status){
document.getElementById(id).classList.add('invalid');
el.style.display='block';
}else{
document.getElementById(id).classList.remove('invalid');
el.style.display='none';
}
}

function validateField(field){

let value=field.value.trim();

switch(field.id){

case 'full_name':
return value.length>2;

case 'email':
return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

case 'gender':
return value!=='';    

case 'dob':
if(!value) return false;
return new Date(value)<=new Date();

case 'phone':
return /^\d{10}$/.test(value);

case 'nrc':
return /^\d{6}\/\d{1,2}\/\d{1,2}$/.test(value);

case 'nok_full_name':
return value.length>2;

case 'nok_phone':
return /^\d{10}$/.test(value);

default:
return false;
}
}

function validateForm(){

let valid=true;

fields.forEach(field=>{

const isValid=validateField(field);

showError(field.id,!isValid);

if(!isValid){
valid=false;
}

});

registerBtn.disabled=!valid;
}

fields.forEach((field,index)=>{

field.addEventListener('focus',function(){

for(let i=0;i<index;i++){

if(!validateField(fields[i])){

alert('Please complete the previous field correctly first.');

fields[i].focus();

return;
}
}
});

field.addEventListener('input',function(){

if(field.id==='phone' || field.id==='nok_phone'){
field.value=field.value.replace(/\D/g,'').slice(0,10);
}

validateForm();
});

field.addEventListener('change',validateForm);

});

validateForm();

</script>

</body>
</html>


