<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$roleTableMap = [
    'Doctor' => ['table' => 'doctors', 'id_col' => 'doctor_id'],
    'Patient' => ['table' => 'patients', 'id_col' => 'patient_id'],
    'Nurse' => ['table' => 'nurses', 'id_col' => 'nurse_id'],
    'Receptionist' => ['table' => 'receptionists', 'id_col' => 'receptionist_id'],
    'Pharmacist' => ['table' => 'pharmacists', 'id_col' => 'pharmacist_id'],
    'Lab Staff' => ['table' => 'lab_staff', 'id_col' => 'lab_staff_id']
];

if (!isset($roleTableMap[$role])) {
    die("Unknown role");
}

$table = $roleTableMap[$role]['table'];
$id_col = $roleTableMap[$role]['id_col'];

$sql = "SELECT * FROM $table WHERE $id_col = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'], $_POST['confirm_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($new_password === $confirm_password && strlen($new_password) >= 6) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE $table SET password = ? WHERE $id_col = ?");
        $update->bind_param("ss", $hashed, $user_id);
        $update->execute();
        $update->close();
        $message = "Password updated successfully";
    } else {
        $message = "Passwords do not match or too short (min 6 chars)";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Tahoma,Verdana,sans-serif}
body{background:#f4f7fb;color:#333}

header{
    background:#0f4c81;
    color:#fff;
    padding:15px 30px;
    display:flex;
    justify-content:space-between;
    align-items:center
}
.header-left{display:flex;align-items:center;gap:12px}
.header-left img{width:40px}

.container{display:flex;min-height:calc(100vh - 70px)}

.sidebar{
    width:240px;
    background:#fff;
    box-shadow:2px 0 10px rgba(0,0,0,.05);
    padding-top:20px
}
.sidebar ul{list-style:none}
.sidebar ul li{
    padding:15px 25px;
    font-size:14px;
    cursor:pointer;
    border-left:4px solid transparent
}
.sidebar ul li:hover,
.sidebar ul li.active{
    background:#f0f6ff;
    border-left:4px solid #0f4c81;
    color:#0f4c81;
    font-weight:600
}
.sidebar ul li a{
    text-decoration:none;
    color:inherit;
    display:block;
    width:100%
}

.main-content{
    flex:1;
    padding:30px
}

.card{
    background:#fff;
    padding:25px;
    border-radius:10px;
    box-shadow:0 8px 20px rgba(0,0,0,.05);
    margin-bottom:25px
}

.section-title{
    margin-bottom:15px;
    font-weight:600;
    color:#0f4c81
}

.form-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:15px
}

.form-group label{
    font-size:13px;
    margin-bottom:5px;
    display:block
}

.form-group input{
    width:100%;
    padding:10px;
    border-radius:6px;
    border:1px solid #ccc;
    font-size:14px
}

input[readonly]{
    background:#f0f0f0;
}

button{
    background:#1fa2ff;
    color:#fff;
    border:none;
    padding:8px 14px;
    border-radius:5px;
    font-size:13px;
    cursor:pointer
}
button:hover{background:#1685d9}
.message{margin-top:10px;color:green}
</style>
</head>
<body>

<header>
<div class="header-left">
<h1>MediPulse</h1>
</div>
<div>Logged in as <strong><?= e($user['full_name']) ?></strong> (<?= e($role) ?>)</div>
</header>

<div class="container">

<nav class="sidebar">
<ul>
<li><a href="javascript:history.back()">← Back</a></li>
</ul>
</nav>

<main class="main-content">

<div class="card">
<h3 class="section-title">Profile Details</h3>
<div class="form-grid">
<div class="form-group">
<label>ID</label>
<input type="text" value="<?= e($user[$id_col]) ?>" readonly>
</div>

<div class="form-group">
<label>Full Name</label>
<input type="text" value="<?= e($user['full_name']) ?>" readonly>
</div>

<div class="form-group">
<label>Email</label>
<input type="email" value="<?= e($user['email']) ?>" readonly>
</div>

<div class="form-group">
<label>Phone</label>
<input type="text" value="<?= e($user['phone']) ?>" readonly>
</div>

<div class="form-group">
<label>Gender</label>
<input type="text" value="<?= e($user['gender']) ?>" readonly>
</div>

<div class="form-group">
<label>Date of Birth</label>
<input type="date" value="<?= e($user['dob']) ?>" readonly>
</div>

<div class="form-group">
<label>NRC</label>
<input type="text" value="<?= e($user['nrc']) ?>" readonly>
</div>

<div class="form-group">
<label>Next of Kin Name</label>
<input type="text" value="<?= e($user['nok_full_name']) ?>" readonly>
</div>

<div class="form-group">
<label>Next of Kin Phone</label>
<input type="text" value="<?= e($user['nok_phone']) ?>" readonly>
</div>
</div>
</div>

<div class="card">
<h3 class="section-title">Change Password</h3>
<form method="POST">
<div class="form-grid">
<div class="form-group">
<label>New Password</label>
<input type="password" name="new_password" required>
</div>
<div class="form-group">
<label>Confirm Password</label>
<input type="password" name="confirm_password" required>
</div>
</div>
<button type="submit">Update Password</button>
<?php if($message): ?>
<div class="message"><?= e($message) ?></div>
<?php endif; ?>
</form>
</div>

</main>
</div>

</body>
</html>