<?php
ob_start();
session_start();
include 'db.php';

$error = '';
$success = '';
$step = 'login';


if (isset($_POST['check_id'])) {

    $patient_id = trim($_POST['patient_id']);

    $stmt = $conn->prepare("SELECT patient_id, password FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $patient = $result->fetch_assoc();

        if (empty($patient['password'])) {
            $_SESSION['temp_patient_id'] = $patient_id;
            $step = 'setup';
        } else {
            $error = "Password already set. Please login.";
        }

    } else {
        $error = "Patient ID not found.";
    }
}

if (isset($_POST['set_password'])) {

    if (!isset($_SESSION['temp_patient_id'])) {
        header("Location: patient-login.php");
        exit;
    }

    $patient_id = $_SESSION['temp_patient_id'];
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if ($password !== $confirm) {
        $error = "Passwords do not match.";
        $step = 'setup';
    }

    elseif (
        strlen($password) < 8 ||
        !preg_match("/[A-Z]/", $password) ||
        !preg_match("/[a-z]/", $password) ||
        !preg_match("/[0-9]/", $password) ||
        !preg_match("/[\W]/", $password)
    ) {
        $error = "Password does not meet required rules.";
        $step = 'setup';
    }

    else {

        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE patients SET password = ? WHERE patient_id = ?");
        $stmt->bind_param("ss", $hashed, $patient_id);
        $stmt->execute();

        $success = "Password set successfully. You may now login.";

        unset($_SESSION['temp_patient_id']);
        $step = 'login';
    }
}

if (isset($_POST['login'])) {

    $patient_id = $_POST['patient_id'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
    $stmt->bind_param("s", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {

        $patient = $result->fetch_assoc();

        if (!password_verify($password, $patient['password'])) {
            $error = "Incorrect password.";
        }
        else {

            $_SESSION['user_id'] = $patient['patient_id'];
            $_SESSION['full_name'] = $patient['full_name'];
            $_SESSION['role'] = "Patient";

            header("Location: patient-dash.php");
            exit;
        }

    } else {
        $error = "Patient not found.";
    }
}

ob_end_flush();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>MediPulse | Patient Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
body {
    min-height:100vh;
    background:url('background.jpg') no-repeat center center fixed;
    background-size:cover;
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:Segoe UI;
}
.login-container{
    background:#fff;
    width:100%;
    max-width:420px;
    padding:40px;
    border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,0.25);
}
.logo{text-align:center;}
.logo img{width:280px;}
h2{text-align:center;margin:20px 0;}
.form-group{margin-bottom:15px;position:relative;}
input{
    width:100%;
    padding:12px;
    border-radius:6px;
    border:1px solid #ccc;
}
.eye{
    position:absolute;
    right:10px;
    top:38px;
    cursor:pointer;
}
.login-btn{
    width:100%;
    padding:12px;
    background:#0f4c81;
    color:#fff;
    border:none;
    border-radius:6px;
}
.error{color:red;text-align:center;margin-bottom:10px;}
.success{color:green;text-align:center;margin-bottom:10px;}
.forgot{text-align:right;margin-top:-10px;margin-bottom:15px;}
.forgot a{font-size:13px;color:#0f4c81;text-decoration:none;}
ul{font-size:12px;padding-left:18px;color:#555;}
</style>
</head>
<body>

<div class="login-container">
<div class="logo"><img src="logo1.png"></div>
<h2>Patient Login</h2>

<?php if($error) echo "<div class='error'>$error</div>"; ?>
<?php if($success) echo "<div class='success'>$success</div>"; ?>

<?php if($step == 'setup'): ?>

<form method="post">

<div class="form-group">
<label>Password</label>
<input type="password" name="password" id="password" required onkeyup="checkStrength()">
<span class="eye" onclick="togglePassword('password')">👁</span>
</div>

<div class="form-group">
<label>Confirm Password</label>
<input type="password" name="confirm_password" id="confirm_password" required>
<span class="eye" onclick="togglePassword('confirm_password')">👁</span>
</div>

<ul>
<li>Minimum 8 characters</li>
<li>Uppercase & lowercase letter</li>
<li>At least 1 number</li>
<li>At least 1 special character</li>
</ul>

<small id="strengthText"></small>

<button type="submit" name="set_password" class="login-btn">Set Password</button>

</form>

<?php else: ?>

<form method="post">

<div class="form-group">
<label>Patient ID</label>
<input type="text" name="patient_id" required>
</div>

<div class="form-group">
<label>Password</label>
<input type="password" name="password" id="login_password">
<span class="eye" onclick="togglePassword('login_password')">👁</span>
</div>

<div class="forgot">
<a href="patient-forgot-password.php">Forgot Password?</a>
</div>

<button type="submit" name="login" class="login-btn">Login</button>
</form>

<hr style="margin:20px 0;">

<form method="post">
<div class="form-group">
<label>First Time Login? Enter Patient ID</label>
<input type="text" name="patient_id" required>
</div>
<button type="submit" name="check_id" class="login-btn">Continue</button>
</form>

<?php endif; ?>

</div>

<script>
function togglePassword(id){
    const input=document.getElementById(id);
    input.type = input.type === "password" ? "text" : "password";
}

function checkStrength(){
    const p=document.getElementById("password").value;
    const t=document.getElementById("strengthText");
    let s=0;
    if(p.length>=8)s++;
    if(/[A-Z]/.test(p))s++;
    if(/[a-z]/.test(p))s++;
    if(/[0-9]/.test(p))s++;
    if(/[\W]/.test(p))s++;

    if(s<=2){t.innerHTML="Weak";t.style.color="red";}
    else if(s<=4){t.innerHTML="Medium";t.style.color="orange";}
    else{t.innerHTML="Strong";t.style.color="green";}
}
</script>

</body>
</html>
