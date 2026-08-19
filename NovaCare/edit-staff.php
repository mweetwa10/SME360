<?php
session_start();
include 'db.php';

$role = $_GET['role'] ?? '';
$id = $_GET['id'] ?? '';

$table = '';
$idColumn = '';

switch ($role) {
    case "Doctor": $table="doctors"; $idColumn="doctor_id"; break;
    case "Nurse": $table="nurses"; $idColumn="nurse_id"; break;
    case "Pharmacist": $table="pharmacists"; $idColumn="pharmacist_id"; break;
    case "Lab Staff": $table="lab_staff"; $idColumn="lab_staff_id"; break;
    case "Receptionist": $table="receptionists"; $idColumn="receptionist_id"; break;
}

$stmt = $conn->prepare("SELECT * FROM $table WHERE $idColumn=?");
$stmt->bind_param("s", $id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $gender = $_POST['gender'];
    $dob = $_POST['dob'];
    $nrc = $_POST['nrc'];
    $nok_name = $_POST['nok_name'];
    $nok_phone = $_POST['nok_phone'];

    $update = $conn->prepare("
        UPDATE $table SET 
        full_name=?, email=?, phone=?, gender=?, dob=?, nrc=?, 
        nok_full_name=?, nok_phone=? 
        WHERE $idColumn=?
    ");

    $update->bind_param(
        "sssssssss",
        $full_name, $email, $phone, $gender, $dob, $nrc,
        $nok_name, $nok_phone, $id
    );

    if ($update->execute()) {
        $msg = "Staff updated successfully!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Staff | MediPulse</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Tahoma,Verdana,sans-serif}
body{background:#f4f7fb;color:#333}

header{background:#0f4c81;color:#fff;padding:15px 30px;display:flex;justify-content:space-between;align-items:center}
.header-left{display:flex;align-items:center;gap:12px}
.header-left img{width:40px}
.header-left h1{font-size:20px}

.container{display:flex;min-height:calc(100vh - 70px)}

.sidebar{width:250px;background:#fff;box-shadow:2px 0 10px rgba(0,0,0,.05);padding-top:20px}
.sidebar ul{list-style:none}
.sidebar ul li{padding:15px 25px;font-size:14px;cursor:pointer;border-left:4px solid transparent;transition:.3s}
.sidebar ul li:hover,.sidebar ul li.active{background:#f0f6ff;border-left:4px solid #0f4c81;color:#0f4c81;font-weight:600}
.sidebar ul li a{text-decoration:none;color:inherit;display:block}

.main-content{flex:1;padding:30px}
.page-title{margin-bottom:20px}
.page-title h2{font-size:22px}
.page-title p{font-size:14px;color:#666}

.form-card{
    background:#fff;
    padding:25px;
    border-radius:10px;
    box-shadow:0 8px 20px rgba(0,0,0,.05);
    max-width:600px
}

.form-group{margin-bottom:15px}
.form-group label{display:block;margin-bottom:5px;font-size:13px}
.form-group input, .form-group select{
    width:100%;
    padding:10px;
    border:1px solid #ccc;
    border-radius:6px;
    font-size:14px
}

.btn{
    padding:10px 15px;
    background:#0f4c81;
    color:#fff;
    border:none;
    border-radius:6px;
    cursor:pointer
}
.btn:hover{background:#0c3c66}

.alert{
    margin-bottom:15px;
    padding:10px;
    border-radius:6px;
    background:#d4edda;
    color:#155724
}
</style>
</head>

<body>

<header>
<h1>MediPulse</h1>
</div>
<div>Logged in as: <strong>Administrator</strong></div>
</header>

<div class="container">

<nav class="sidebar">
<ul>
<li><a href="admin-dash.php">Dashboard</a></li>
<li class="active"><a href="manage-staff.php">Manage Staff</a></li>
</ul>
</nav>

<main class="main-content">

<div class="page-title">
<h2>Edit <?= htmlspecialchars($role) ?></h2>
<p>Modify staff details below</p>
</div>

<div class="form-card">

<?php if($msg): ?>
<div class="alert"><?= $msg ?></div>
<?php endif; ?>

<form method="POST">

<div class="form-group">
<label>Staff ID</label>
<input type="text" value="<?= $data[$idColumn] ?>" readonly>
</div>

<div class="form-group">
<label>Full Name</label>
<input type="text" name="full_name" value="<?= $data['full_name'] ?>" required>
</div>

<div class="form-group">
<label>Email</label>
<input type="email" name="email" value="<?= $data['email'] ?>" required>
</div>

<div class="form-group">
<label>Phone</label>
<input type="text" name="phone" value="<?= $data['phone'] ?>" required>
</div>

<div class="form-group">
<label>Gender</label>
<select name="gender" required>
<option <?= $data['gender']=="Male"?"selected":"" ?>>Male</option>
<option <?= $data['gender']=="Female"?"selected":"" ?>>Female</option>
</select>
</div>

<div class="form-group">
<label>DOB</label>
<input type="date" name="dob" value="<?= $data['dob'] ?>" required>
</div>

<div class="form-group">
<label>NRC</label>
<input type="text" name="nrc" value="<?= $data['nrc'] ?>" required>
</div>

<div class="form-group">
<label>Next of Kin Name</label>
<input type="text" name="nok_name" value="<?= $data['nok_full_name'] ?>" required>
</div>

<div class="form-group">
<label>Next of Kin Phone</label>
<input type="text" name="nok_phone" value="<?= $data['nok_phone'] ?>" required>
</div>

<button class="btn" type="submit">Update Staff</button>

</form>

</div>

</main>

</div>

</body>
</html>