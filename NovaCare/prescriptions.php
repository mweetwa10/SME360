<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Pharmacist') {
    header("Location: login.php");
    exit;
}

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dispense_id'], $_POST['medicine_id'], $_POST['quantity_dispensed'])) {
    $prescriptionId    = (int)$_POST['dispense_id'];
    $medicineId        = (int)$_POST['medicine_id'];
    $quantityDispensed = (int)$_POST['quantity_dispensed'];

    $conn->begin_transaction();
    try {
        $stmt1 = $conn->prepare(
            "UPDATE prescriptions 
             SET status='Dispensed', quantity_dispensed=? 
             WHERE prescription_id=? AND status='Pending'"
        );
        $stmt1->bind_param("ii", $quantityDispensed, $prescriptionId);
        $stmt1->execute();

        $stmt2 = $conn->prepare(
            "UPDATE medicines 
             SET stock_quantity = stock_quantity - ? 
             WHERE medicine_id=? AND stock_quantity >= ?"
        );
        $stmt2->bind_param("iii", $quantityDispensed, $medicineId, $quantityDispensed);
        $stmt2->execute();

        $conn->commit();
        header("Location: prescriptions.php");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        die("Error dispensing: " . $e->getMessage());
    }
}

$totalPrescriptions     = $conn->query("SELECT COUNT(*) AS total FROM prescriptions")->fetch_assoc()['total'];
$pending                = $conn->query("SELECT COUNT(*) AS total FROM prescriptions WHERE status='Pending'")->fetch_assoc()['total'];
$dispensed              = $conn->query("SELECT COUNT(*) AS total FROM prescriptions WHERE status='Dispensed'")->fetch_assoc()['total'];
$totalQuantityDispensed = $conn->query("SELECT SUM(quantity_dispensed) AS total FROM prescriptions")->fetch_assoc()['total'];

$prescriptions = [];
$sql = "
    SELECT 
        prescriptions.prescription_id,
        prescriptions.status,
        prescriptions.dosage,
        prescriptions.quantity_dispensed,
        prescriptions.medicine_id,
        patients.full_name  AS patient_name,
        doctors.full_name   AS doctor_name,
        medicines.name      AS medication
    FROM prescriptions
    JOIN patients  ON prescriptions.patient_id  = patients.patient_id
    JOIN doctors   ON prescriptions.doctor_id   = doctors.doctor_id
    JOIN medicines ON prescriptions.medicine_id = medicines.medicine_id
    ORDER BY prescriptions.prescription_id DESC
";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) $prescriptions[] = $row;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Prescriptions</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif}
body{background:#f4f7fb;color:#333}
header{background:#0f4c81;color:#fff;padding:15px 30px;display:flex;justify-content:space-between;align-items:center}
.container{display:flex;min-height:calc(100vh - 70px)}
.sidebar{width:240px;background:#fff;box-shadow:2px 0 10px rgba(0,0,0,0.05);padding-top:20px}
.sidebar ul{list-style:none}
.sidebar ul li{padding:15px 25px;font-size:14px;border-left:4px solid transparent}
.sidebar ul li.active{background:#f0f6ff;border-left:4px solid #0f4c81;font-weight:600}
.sidebar ul li a{text-decoration:none;color:inherit}
.main-content{flex:1;padding:30px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:30px}
.card{background:#fff;padding:20px;border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,0.05)}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:14px;border-bottom:1px solid #eee}
th{background:#0f4c81;color:#fff}
.btn{padding:6px 12px;background:#1fa2ff;color:#fff;border:none;border-radius:4px;cursor:pointer}
input[type=number]{padding:5px;border:1px solid #ccc;border-radius:4px;width:70px}
form.inline{display:flex;gap:5px;align-items:center}
.status.Pending{color:orange;font-weight:600}
.status.Dispensed{color:green;font-weight:600}
</style>
</head>

<body>

<header>
  <h1>MediPulse</h1>
  <strong>Pharmacist</strong>
</header>

<div class="container">
<nav class="sidebar">
  <ul>
    <li class="active">
        <a href="prescriptions.php">
            <i class="fas fa-file-prescription"></i> Prescriptions
        </a>
    </li>
    <li>
        <a href="pharmacy-dash.php">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
    </li>
  </ul>
</nav>

<main class="main-content">

<div class="cards">
  <div class="card"><h3>Total Prescriptions</h3><span><?= $totalPrescriptions ?></span></div>
  <div class="card"><h3>Pending</h3><span><?= $pending ?></span></div>
  <div class="card"><h3>Dispensed</h3><span><?= $dispensed ?></span></div>
  <div class="card"><h3>Total Quantity Dispensed</h3><span><?= $totalQuantityDispensed ?? 0 ?></span></div>
</div>

<div class="card">
<h3>All Prescriptions</h3>
<table>
<thead>
<tr>
<th>Patient</th>
<th>Doctor</th>
<th>Medication</th>
<th>Dosage</th>
<th>Status</th>
<th>Quantity Dispensed</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php if ($prescriptions): foreach ($prescriptions as $p): ?>
<tr>
<td><?= htmlspecialchars($p['patient_name']) ?></td>
<td><?= htmlspecialchars($p['doctor_name']) ?></td>
<td><?= htmlspecialchars($p['medication']) ?></td>
<td><?= htmlspecialchars($p['dosage']) ?></td>
<td class="status <?= $p['status'] ?>"><?= $p['status'] ?></td>
<td><?= $p['quantity_dispensed'] ?></td>
<td>
<?php if ($p['status'] === 'Pending'): ?>
<form method="post" class="inline">
  <input type="hidden" name="dispense_id" value="<?= $p['prescription_id'] ?>">
  <input type="hidden" name="medicine_id" value="<?= $p['medicine_id'] ?>">
  <input type="number" name="quantity_dispensed" placeholder="Qty" min="1" required>
  <input type="hidden" name="action" value="dispense">
  <button class="btn">Mark as Dispensed</button>
</form>
<?php else: ?>—<?php endif; ?>
</td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="7" style="text-align:center;">No prescriptions found</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

</main>
</div>
</body>
</html>
