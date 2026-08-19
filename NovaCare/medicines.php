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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $name        = trim($_POST['name'] ?? '');
        $quantity    = (int)($_POST['quantity'] ?? 0);
        $unit        = trim($_POST['unit'] ?? '');
        $expiry_date = trim($_POST['expiry_date'] ?? '');

        if ($name && $quantity > 0 && in_array($unit, ['tablets', 'injection']) && $expiry_date) {

            
            $check = $conn->prepare(
                "SELECT medicine_id FROM medicines WHERE name = ? AND unit = ? LIMIT 1"
            );
            $check->bind_param("ss", $name, $unit);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                
                $check->bind_result($existingId);
                $check->fetch();
                $check->close();

                $update = $conn->prepare(
                    "UPDATE medicines SET stock_quantity = stock_quantity + ?, expiry_date = ? WHERE medicine_id = ?"
                );
                $update->bind_param("isi", $quantity, $expiry_date, $existingId);
                $update->execute();
                $update->close();

            } else {
                $check->close();

                
                $insert = $conn->prepare(
                    "INSERT INTO medicines (name, stock_quantity, unit, expiry_date) VALUES (?, ?, ?, ?)"
                );
                $insert->bind_param("siss", $name, $quantity, $unit, $expiry_date);
                $insert->execute();
                $insert->close();
            }
        }

    } elseif ($action === 'topup' && isset($_POST['medicine_id'])) {
        $medicineId  = (int)$_POST['medicine_id'];
        $quantity    = (int)($_POST['quantity'] ?? 0);
        $expiry_date = trim($_POST['expiry_date'] ?? '');

        if ($quantity > 0 && $expiry_date) {
            
            $stmt = $conn->prepare(
                "UPDATE medicines SET stock_quantity = stock_quantity + ?, expiry_date = ? WHERE medicine_id = ?"
            );
            $stmt->bind_param("isi", $quantity, $expiry_date, $medicineId);
            $stmt->execute();
            $stmt->close();
        } elseif ($quantity > 0) {
            
            $stmt = $conn->prepare(
                "UPDATE medicines SET stock_quantity = stock_quantity + ? WHERE medicine_id = ?"
            );
            $stmt->bind_param("ii", $quantity, $medicineId);
            $stmt->execute();
            $stmt->close();
        }
    }

    header("Location: medicines.php");
    exit;
}

$medicines = [];
$res = $conn->query("SELECT * FROM medicines ORDER BY name ASC");
while ($row = $res->fetch_assoc()) $medicines[] = $row;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Medicines</title>
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
input[type=text],input[type=number],select,input[type=date]{padding:5px;border:1px solid #ccc;border-radius:4px;width:150px}
form.inline{display:flex;gap:5px;align-items:center}
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
        <a href="medicines.php">
            <i class="fas fa-pills"></i> Medicine
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
  <div class="card">
    <h3>Add New Medicine</h3>
    <form method="post" class="inline">
      <input type="text" name="name" placeholder="Medicine Name" required>
      <input type="number" name="quantity" placeholder="Stock Qty" min="1" required>
      <select name="unit" required>
        <option value="">Type</option>
        <option value="tablets">Tablets</option>
        <option value="injection">Injection</option>
      </select>
      <input type="date" name="expiry_date" id="expiry-add" required>
      <input type="hidden" name="action" value="add">
      <button class="btn">Add</button>
    </form>
  </div>
</div>

<div class="card">
<h3>Available Medicines</h3>
<table>
<thead>
<tr><th>Medicine</th><th>Stock Quantity</th><th>Type</th><th>Expiry Date</th><th>Action</th></tr>
</thead>
<tbody>
<?php if ($medicines): foreach ($medicines as $m): ?>
<tr>
<td><?= htmlspecialchars($m['name']) ?></td>
<td><?= (int)$m['stock_quantity'] ?></td>
<td><?= htmlspecialchars($m['unit']) ?></td>
<td><?= htmlspecialchars($m['expiry_date']) ?></td>
<td>
<form method="post" class="inline">
  <input type="hidden" name="medicine_id" value="<?= (int)$m['medicine_id'] ?>">
  <input type="number" name="quantity" placeholder="Add Qty" min="1" required>
  <input type="date" name="expiry_date" class="expiry-topup" title="New batch expiry date" required>
  <input type="hidden" name="action" value="topup">
  <button class="btn">Top Up</button>
</form>
</td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="5" style="text-align:center;">No medicines available</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

</main>
</div>
<script>
const today = new Date().toISOString().split('T')[0];
document.getElementById('expiry-add').min = today;
document.querySelectorAll('.expiry-topup').forEach(el => el.min = today);
</script>
</body>
</html>