<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Pharmacist') {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispense_id'], $_POST['medicine_id'], $_POST['quantity'])) {
    $prescriptionId = (int)$_POST['dispense_id'];
    $medicineId     = (int)$_POST['medicine_id'];
    $quantity       = (int)$_POST['quantity'];

    $conn->begin_transaction();
    try {
        $stmt1 = $conn->prepare(
            "UPDATE prescriptions 
             SET status = 'Dispensed', quantity_dispensed = ?, dispensed_at = NOW() 
             WHERE prescription_id = ? AND status = 'Pending'"
        );
        $stmt1->bind_param("ii", $quantity, $prescriptionId);
        $stmt1->execute();

        $stmt2 = $conn->prepare(
            "UPDATE medicines 
             SET stock_quantity = stock_quantity - ? 
             WHERE medicine_id = ? AND stock_quantity >= ?"
        );
        $stmt2->bind_param("iii", $quantity, $medicineId, $quantity);
        $stmt2->execute();

        $conn->commit();
        header("Location: pharmacy-dash.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        die("Dispensing failed: " . $e->getMessage());
    }
}

$totalPrescriptions = $conn->query("SELECT COUNT(*) AS total FROM prescriptions")->fetch_assoc()['total'];
$pending            = $conn->query("SELECT COUNT(*) AS total FROM prescriptions WHERE status = 'Pending'")->fetch_assoc()['total'];
$dispensed          = $conn->query("SELECT COUNT(*) AS total FROM prescriptions WHERE status = 'Dispensed'")->fetch_assoc()['total'];
$availableMedicines = $conn->query("SELECT COUNT(*) AS total FROM medicines")->fetch_assoc()['total'];

$todayTotal      = $conn->query("SELECT COUNT(*) AS total FROM prescriptions WHERE DATE(dispensed_at) = CURDATE()")->fetch_assoc()['total'];
$todayDispensed  = $conn->query("SELECT COUNT(*) AS total FROM prescriptions WHERE status='Dispensed' AND DATE(dispensed_at) = CURDATE()")->fetch_assoc()['total'];
$todayPending    = $conn->query("SELECT COUNT(*) AS total FROM prescriptions WHERE status='Pending' AND DATE(created_at) = CURDATE()")->fetch_assoc()['total'];

$prescriptions = [];
$sql = "
    SELECT 
        prescriptions.prescription_id,
        prescriptions.dosage,
        prescriptions.frequency,
        prescriptions.duration,
        prescriptions.quantity_dispensed,
        prescriptions.dispensed_at,
        prescriptions.status,
        prescriptions.medicine_id,
        patients.full_name  AS patient_name,
        doctors.full_name   AS doctor_name,
        medicines.name      AS medication,
        medicines.stock_quantity
    FROM prescriptions
    JOIN patients  ON prescriptions.patient_id  = patients.patient_id
    JOIN doctors   ON prescriptions.doctor_id   = doctors.doctor_id
    JOIN medicines ON prescriptions.medicine_id = medicines.medicine_id
    ORDER BY prescriptions.prescription_id DESC
";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $prescriptions[] = $row;
}

$lowStock  = [];
$threshold = 100;
$resLow    = $conn->query("SELECT name, stock_quantity FROM medicines WHERE stock_quantity <= $threshold ORDER BY stock_quantity ASC");
while ($r = $resLow->fetch_assoc()) {
    $lowStock[] = $r;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Pharmacy Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* {margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif}
body {background:#f4f7fb;color:#333}
header {background:#0f4c81;color:#fff;padding:15px 30px;display:flex;justify-content:space-between;align-items:center}
.container {display:flex;min-height:calc(100vh - 70px)}
.sidebar {width:240px;background:#fff;box-shadow:2px 0 10px rgba(0,0,0,0.05);padding-top:20px}
.sidebar ul {list-style:none}
.sidebar ul li {padding:15px 25px;font-size:14px;border-left:4px solid transparent}
.sidebar ul li.active {background:#f0f6ff;border-left:4px solid #0f4c81;font-weight:600}
.sidebar ul li a {text-decoration:none;color:inherit}
.main-content {flex:1;padding:30px}
.cards {display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:30px}
.card {background:#fff;padding:20px;border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,0.05)}
.table-wrapper {overflow-x:auto}
table {width:100%;border-collapse:collapse;min-width:900px}
th,td {padding:14px;border-bottom:1px solid #eee;text-align:left}
th {background:#0f4c81;color:#fff}
.btn {padding:6px 12px;background:#1fa2ff;color:#fff;border:none;border-radius:4px;cursor:pointer}
.status.Pending {color:orange;font-weight:600}
.status.Dispensed {color:green;font-weight:600}
input[type=number]{width:60px;padding:3px}
</style>
</head>

<body>

<header>
  <h1>MediPulse</h1>
  <strong>Logged in as: <?= htmlspecialchars($_SESSION['full_name'] ?? 'Guest') ?></strong>
</header>

<div class="container">
<nav class="sidebar">
  <ul>
    <li class="active"><a href="pharmacy-dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
    <li><a href="medicines.php"><i class="fas fa-pills"></i> Medicine</a></li>
    <li><a href="prescriptions.php"><i class="fas fa-file-prescription"></i> Prescriptions</a></li>
    <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
    <li><a href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
  </ul>
</nav>

<main class="main-content">

<div class="cards">
  <div class="card"><h3>Total Prescriptions</h3><span><?= $totalPrescriptions ?></span></div>
  <div class="card"><h3>Pending</h3><span><?= $pending ?></span></div>
  <div class="card"><h3>Dispensed</h3><span><?= $dispensed ?></span></div>
  <div class="card"><h3>Available Medicines</h3><span><?= $availableMedicines ?></span></div>
</div>

<div class="card table-wrapper">
<h3>Prescriptions</h3>
<table>
<thead>
<tr>
<th>Patient</th>
<th>Doctor</th>
<th>Medication</th>
<th>Dosage</th>
<th>Frequency</th>
<th>Duration</th>
<th>Qty Dispensed</th>
<th>Dispensed At</th>
<th>Status</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php foreach ($prescriptions as $p): ?>
<tr>
<td><?= htmlspecialchars($p['patient_name']) ?></td>
<td><?= htmlspecialchars($p['doctor_name']) ?></td>
<td><?= htmlspecialchars($p['medication']) ?></td>
<td><?= htmlspecialchars($p['dosage']) ?></td>
<td><?= htmlspecialchars($p['frequency']) ?></td>
<td><?= htmlspecialchars($p['duration']) ?></td>
<td><?= $p['quantity_dispensed'] ?></td>
<td><?= $p['dispensed_at'] ?></td>
<td class="status <?= $p['status'] ?>"><?= $p['status'] ?></td>
<td>
<?php if ($p['status'] === 'Pending'): ?>
<form method="post" style="display:flex; gap:5px; align-items:center;">
  <input type="hidden" name="dispense_id" value="<?= $p['prescription_id'] ?>">
  <input type="hidden" name="medicine_id" value="<?= $p['medicine_id'] ?>">
  <input type="number" name="quantity" min="1" max="<?= $p['stock_quantity'] ?>" placeholder="Qty" required>
  <button class="btn" name="dispense">Dispense</button>
</form>
<?php else: ?>—<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="card" style="margin-top:25px">
<h3>Low Stock Medicines (Less than <?= $threshold ?>)</h3>
<table>
<thead><tr><th>Medicine</th><th>Stock</th></tr></thead>
<tbody>
<?php if ($lowStock): foreach ($lowStock as $m): ?>
<tr><td><?= htmlspecialchars($m['name']) ?></td><td><?= $m['stock_quantity'] ?></td></tr>
<?php endforeach; else: ?>
<tr><td colspan="2">No low-stock medicines</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<div class="card" style="margin-top:25px">
<h3>Today’s Pharmacy Report</h3>
<p>Total Today: <strong><?= $todayTotal ?></strong></p>
<p>Dispensed Today: <strong><?= $todayDispensed ?></strong></p>
<p>Pending Today: <strong><?= $todayPending ?></strong></p>
</div>

</main>
</div>
</body>
</html>
