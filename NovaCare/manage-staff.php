<?php
session_start();
include 'db.php';

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    echo "Access denied. Admins only.";
    exit();
}

$role = $_GET['role'] ?? '';
$table = '';
$idColumn = '';

switch ($role) {
    case "Doctor": $table="doctors"; $idColumn="doctor_id"; break;
    case "Nurse": $table="nurses"; $idColumn="nurse_id"; break;
    case "Pharmacist": $table="pharmacists"; $idColumn="pharmacist_id"; break;
    case "Lab Staff": $table="lab_staff"; $idColumn="lab_staff_id"; break;
    case "Receptionist": $table="receptionists"; $idColumn="receptionist_id"; break;
}

if (isset($_GET['delete']) && $table) {
    $id = $_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM $table WHERE $idColumn=?");
    $stmt->bind_param("s", $id);
    $stmt->execute();

    header("Location: manage-staff.php?role=$role");
    exit();
}

$staff = [];
if ($table) {
    $result = $conn->query("SELECT * FROM $table");
    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Manage Staff</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
.sidebar i {
    margin-right: 10px;
}

.main-content{flex:1;padding:30px}
.page-title{margin-bottom:20px}
.page-title h2{font-size:22px}
.page-title p{font-size:14px;color:#666}

.filter-box{background:#fff;padding:20px;border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,.05);margin-bottom:25px;max-width:400px}
.filter-box label{font-size:14px;margin-bottom:6px;display:block}
.filter-box select{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px}

table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 8px 20px rgba(0,0,0,.05)}
th,td{padding:14px;font-size:14px;border-bottom:1px solid #eee}
th{background:#0f4c81;color:#fff}
tr:hover{background:#f9fbff}

.btn{padding:6px 12px;border:none;border-radius:5px;font-size:12px;cursor:pointer}
.btn-edit{background:#28a745;color:#fff}
.btn-edit:hover{background:#218838}
.btn-delete{background:#dc3545;color:#fff}
.btn-delete:hover{background:#c82333}

.empty{background:#fff;padding:20px;border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,.05);color:#666}
</style>
</head>

<body>

<header>
<div class="header-left">
<h1>MediPulse</h1>
</div>
<div>
Logged in as: 
<strong>
<?php echo isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : 'Administrator'; ?>
</strong>
</div>
</header>

<div class="container">

<nav class="sidebar">
<ul>
<li>
    <a href="admin-dash.php">
        <i class="fa-solid fa-gauge"></i> Dashboard
    </a>
</li>
</ul>
</nav>

<main class="main-content">

<div class="page-title">
<h2>Manage Staff Accounts</h2>
<p>Select a staff category to view, edit, or delete users</p>
</div>

<div class="filter-box">
<form method="GET">
<label>Select Staff Type</label>
<select name="role" onchange="this.form.submit()">
<option value="">-- Choose Role --</option>
<option <?= $role=="Doctor"?"selected":"" ?>>Doctor</option>
<option <?= $role=="Nurse"?"selected":"" ?>>Nurse</option>
<option <?= $role=="Pharmacist"?"selected":"" ?>>Pharmacist</option>
<option <?= $role=="Lab Staff"?"selected":"" ?>>Lab Staff</option>
<option <?= $role=="Receptionist"?"selected":"" ?>>Receptionist</option>
</select>
</form>
</div>

<?php if($role && $staff): ?>

<table>
<tr>
<th>ID</th>
<th>Full Name</th>
<th>Email</th>
<th>Phone</th>
<th>Actions</th>
</tr>

<?php foreach($staff as $row): ?>
<tr>
<td><?= $row[$idColumn] ?></td>
<td><?= $row['full_name'] ?></td>
<td><?= $row['email'] ?></td>
<td><?= $row['phone'] ?></td>
<td>
<a href="edit-staff.php?role=<?= $role ?>&id=<?= $row[$idColumn] ?>">
<button class="btn btn-edit">Edit</button>
</a>

<a href="?role=<?= $role ?>&delete=<?= $row[$idColumn] ?>" onclick="return confirm('Delete this user?')">
<button class="btn btn-delete">Delete</button>
</a>
</td>
</tr>
<?php endforeach; ?>

</table>

<?php elseif($role): ?>
<div class="empty">No records found.</div>
<?php endif; ?>

</main>

</div>

</body>
</html>