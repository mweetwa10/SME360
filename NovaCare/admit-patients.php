<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header("Location: login.php");
    exit;
}

$doctor_id = $_SESSION['user_id'];

$found_patient = null;
$search_error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_patient'])) {
    $search_term = trim($_POST['search_term']);

    if ($search_term !== '') {
        $stmt = $conn->prepare("
            SELECT patient_id, full_name, nrc
            FROM patients
            WHERE patient_id = ? OR nrc = ?
            LIMIT 1
        ");
        $stmt->bind_param("ss", $search_term, $search_term);
        $stmt->execute();
        $found_patient = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$found_patient) {
            $search_error = "No patient found with that ID or NRC.";
        } else {
            
            $chk = $conn->prepare("
                SELECT admission_id FROM admissions
                WHERE patient_id = ? AND status = 'Admitted'
                LIMIT 1
            ");
            $chk->bind_param("s", $found_patient['patient_id']);
            $chk->execute();
            $found_patient['already_admitted'] = (bool) $chk->get_result()->fetch_assoc();
            $chk->close();
        }
    }
}

$occupied = [];

$res = $conn->query("
    SELECT ward_name, room_number
    FROM admissions
    WHERE status = 'Admitted'
");

while ($r = $res->fetch_assoc()) {
    $occupied[$r['ward_name'] . '-' . $r['room_number']] = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admit']) && !empty($_POST['patient_id'])) {

    $patient_id = $_POST['patient_id'];
    $ward       = $_POST['ward_name'];
    $room       = $_POST['room_number'];
    $reason     = $_POST['reason'];

    $key = $ward . '-' . $room;

    $chk = $conn->prepare("SELECT admission_id FROM admissions WHERE patient_id = ? AND status = 'Admitted' LIMIT 1");
    $chk->bind_param("s", $patient_id);
    $chk->execute();
    $already = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($already) {
        $error = "This patient is already currently admitted and has not been discharged yet.";
        $stmt2 = $conn->prepare("SELECT patient_id, full_name, nrc FROM patients WHERE patient_id = ? LIMIT 1");
        $stmt2->bind_param("s", $patient_id);
        $stmt2->execute();
        $found_patient = $stmt2->get_result()->fetch_assoc();
        $found_patient['already_admitted'] = true;
        $stmt2->close();
    } elseif (isset($occupied[$key])) {
        $error = "Ward/Room already occupied.";
    } else {

        $stmt = $conn->prepare("
            INSERT INTO admissions
            (patient_id, doctor_id, ward_name, room_number, reason, status, admitted_at)
            VALUES (?, ?, ?, ?, ?, 'Admitted', NOW())
        ");

        $stmt->bind_param(
            "sssis",
            $patient_id,
            $doctor_id,
            $ward,
            $room,
            $reason
        );

        $stmt->execute();
        $stmt->close();

        $success = "Patient admitted successfully.";

        $occupied[$key] = true;

        $stmt2 = $conn->prepare("SELECT patient_id, full_name, nrc FROM patients WHERE patient_id = ? LIMIT 1");
        $stmt2->bind_param("s", $patient_id);
        $stmt2->execute();
        $found_patient = $stmt2->get_result()->fetch_assoc();
        $found_patient['already_admitted'] = false;
        $stmt2->close();
    }
}

if (isset($_GET['discharge'])) {

    $admission_id = $_GET['discharge'];

    $stmt = $conn->prepare("
        UPDATE admissions
        SET status = 'Discharged',
            discharged_at = NOW()
        WHERE admission_id = ? AND doctor_id = ?
    ");

    $stmt->bind_param("ss", $admission_id, $doctor_id);
    $stmt->execute();
    $stmt->close();

    header("Location: admit-patients.php?slip=$admission_id");
    exit;
}

$slip = null;

if (isset($_GET['slip'])) {

    $id = $_GET['slip'];

    $stmt = $conn->prepare("
        SELECT a.*, p.full_name
        FROM admissions a
        JOIN patients p ON p.patient_id = a.patient_id
        WHERE a.admission_id = ?
    ");

    $stmt->bind_param("s", $id);
    $stmt->execute();
    $slip = $stmt->get_result()->fetch_assoc();
}

$stmt = $conn->prepare("
    SELECT a.*, p.full_name
    FROM admissions a
    JOIN patients p ON p.patient_id = a.patient_id
    WHERE a.doctor_id = ?
    ORDER BY a.admitted_at DESC
");

$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Admit Patients</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

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
}

.container{display:flex;min-height:100vh;}

.sidebar{
    width:240px;
    background:#fff;
    box-shadow:2px 0 10px rgba(0,0,0,0.05);
    padding-top:20px;
}

.sidebar ul{list-style:none;}

.sidebar ul li{
    padding:14px 22px;
    border-left:4px solid transparent;
}

.sidebar ul li a{
    text-decoration:none;
    color:#333;
    display:flex;
    gap:10px;
}

.sidebar ul li:hover{background:#f0f6ff;}

.sidebar ul li.active{
    background:#eaf3ff;
    border-left:4px solid #0f4c81;
}

.main-content{flex:1;padding:30px;}

.card{
    background:#fff;
    padding:25px;
    border-radius:12px;
    box-shadow:0 8px 20px rgba(0,0,0,0.05);
    margin-bottom:25px;
}

.section-title{color:#0f4c81;margin-bottom:15px;}

input,select,textarea{
    width:100%;
    padding:10px;
    margin-top:6px;
    margin-bottom:12px;
    border:1px solid #ccc;
    border-radius:6px;
}

button{
    background:#1fa2ff;
    color:#fff;
    border:none;
    padding:10px;
    border-radius:6px;
    cursor:pointer;
}

table{
    width:100%;
    border-collapse:collapse;
    margin-top:15px;
}

th,td{
    padding:12px;
    border-bottom:1px solid #eee;
    font-size:14px;
}

th{
    background:#0f4c81;
    color:#fff;
}

.status-admitted{color:green;font-weight:600;}
.status-discharged{color:red;font-weight:600;}

.action-btn{
    padding:6px 10px;
    background:#e74c3c;
    color:#fff;
    border:none;
    border-radius:5px;
    text-decoration:none;
}

@media print {
    body *{
        visibility:hidden;
    }

    .print-slip, .print-slip *{
        visibility:visible;
    }

    .print-slip{
        position:absolute;
        top:20px;
        left:50%;
        transform:translateX(-50%);
        width:400px;
        background:#fff;
        border:2px solid #0f4c81;
        padding:20px;
    }

    button, a{
        display:none !important;
    }
}
</style>
</head>

<body>

<?php if ($slip): ?>
<div class="print-slip">
<h3>Discharge Slip</h3>
<hr>

<p><b>Patient:</b> <?= htmlspecialchars($slip['full_name']) ?></p>
<p><b>Ward:</b> <?= $slip['ward_name'] ?></p>
<p><b>Room:</b> <?= $slip['room_number'] ?></p>
<p><b>Admitted:</b> <?= $slip['admitted_at'] ?></p>
<p><b>Discharged:</b> <?= $slip['discharged_at'] ?></p>

<hr>

<button onclick="window.print()">Print Slip</button>
<a href="admit-patients.php"><button>Close</button></a>
</div>
<?php endif; ?>

<header>
    <h2>MediPulse</h2>
</header>

<div class="container">

<nav class="sidebar">
<ul>
    <li><a href="doctor-dash.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a></li>
    <li class="active"><a href="admit-patients.php"><i class="fas fa-procedures"></i>Admit Patients</a></li>
</ul>
</nav>

<div class="main-content">

<div class="card">
<h3 class="section-title">Admit Patient</h3>

<?php if(isset($success)) echo "<p style='color:green'>$success</p>"; ?>
<?php if(isset($error)) echo "<p style='color:red'>$error</p>"; ?>

<form method="POST" style="margin-bottom:0;">
<label>Search Patient by ID or NRC</label>
<div style="display:flex;gap:8px;align-items:flex-start;margin-top:6px;margin-bottom:12px;">
    <input type="text"
           name="search_term"
           placeholder="Enter Patient ID or NRC"
           value="<?= htmlspecialchars($_POST['search_term'] ?? '') ?>"
           style="margin:0;flex:1;"
    >
    <button type="submit" name="search_patient" style="white-space:nowrap;padding:10px 16px;">
        <i class="fas fa-search"></i> Search
    </button>
</div>
</form>

<?php if ($search_error): ?>
    <p style="color:red;margin-bottom:10px;"><?= $search_error ?></p>
<?php endif; ?>

<?php if ($found_patient): ?>
    <?php if ($found_patient['already_admitted']): ?>
        <div style="background:#fff3f3;border:1px solid #f5b3b3;border-radius:6px;padding:12px;margin-bottom:12px;">
            <p style="margin:0 0 4px;color:#c0392b;"><b><i class="fas fa-exclamation-circle"></i> Already Admitted</b></p>
            <p style="margin:0;font-size:14px;">
                <b>ID:</b> <?= htmlspecialchars($found_patient['patient_id']) ?> &nbsp;|&nbsp;
                <b>Name:</b> <?= htmlspecialchars($found_patient['full_name']) ?> &nbsp;|&nbsp;
                <b>NRC:</b> <?= htmlspecialchars($found_patient['nrc']) ?>
            </p>
            <p style="margin:6px 0 0;font-size:13px;color:#c0392b;">This patient is currently admitted and must be discharged before re-admitting.</p>
        </div>
    <?php else: ?>
        <div style="background:#eaf3ff;border:1px solid #b3d4f5;border-radius:6px;padding:12px;margin-bottom:12px;">
            <p style="margin:0 0 4px;color:#0f4c81;"><b><i class="fas fa-check-circle"></i> Patient Found</b></p>
            <p style="margin:0;font-size:14px;">
                <b>ID:</b> <?= htmlspecialchars($found_patient['patient_id']) ?> &nbsp;|&nbsp;
                <b>Name:</b> <?= htmlspecialchars($found_patient['full_name']) ?> &nbsp;|&nbsp;
                <b>NRC:</b> <?= htmlspecialchars($found_patient['nrc']) ?>
            </p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($found_patient && !$found_patient['already_admitted']): ?>
<form method="POST">
<input type="hidden" name="admit" value="1">
<input type="hidden" name="patient_id" value="<?= htmlspecialchars($found_patient['patient_id']) ?>">

<label>Ward</label>
<select name="ward_name" id="ward_select" required onchange="updateRooms()">
<option value="">Select Ward</option>

<optgroup label="Male Wards">
<?php for($w = 1; $w <= 10; $w++): ?>
<option value="Male Ward <?= $w ?>">Male Ward <?= $w ?></option>
<?php endfor; ?>
</optgroup>

<optgroup label="Female Wards">
<?php for($w = 1; $w <= 10; $w++): ?>
<option value="Female Ward <?= $w ?>">Female Ward <?= $w ?></option>
<?php endfor; ?>
</optgroup>

</select>

<label>Room Number</label>
<select name="room_number" id="room_select" required>
<option value="">Select Ward First</option>
</select>

<label>Reason</label>
<textarea name="reason" required></textarea>

<button type="submit">Admit Patient</button>
</form>
<?php endif; ?>
</div>

<div class="card">
<h3 class="section-title">My Admitted Patients</h3>

<table>
<tr>
<th>Patient</th>
<th>Ward</th>
<th>Room</th>
<th>Reason</th>
<th>Admitted</th>
<th>Discharged</th>
<th>Status</th>
<th>Action</th>
</tr>

<?php while($row = $result->fetch_assoc()): ?>
<tr>
<td><?= htmlspecialchars($row['full_name']) ?></td>
<td><?= $row['ward_name'] ?></td>
<td><?= $row['room_number'] ?></td>
<td><?= $row['reason'] ?></td>
<td><?= $row['admitted_at'] ?></td>
<td><?= $row['discharged_at'] ?? '-' ?></td>

<td>
<?= $row['status'] === 'Admitted'
? "<span class='status-admitted'>Admitted</span>"
: "<span class='status-discharged'>Discharged</span>" ?>
</td>

<td>
<?php if($row['status'] === 'Admitted'): ?>
<a class="action-btn" href="?discharge=<?= $row['admission_id'] ?>">
Discharge
</a>
<?php endif; ?>
</td>
</tr>
<?php endwhile; ?>

</table>

</div>

</div>
</div>

<script>
const occupied = <?= json_encode($occupied) ?>;

function updateRooms() {
    const wardSelect = document.getElementById('ward_select');
    const roomSelect = document.getElementById('room_select');
    const ward = wardSelect.value;

    roomSelect.innerHTML = '<option value="">Select Room</option>';

    if (!ward) return;

    for (let r = 1; r <= 10; r++) {
        const key = ward + '-' + r;
        const isOccupied = occupied.hasOwnProperty(key);

        const opt = document.createElement('option');
        opt.value = r;
        opt.textContent = 'Room ' + r + (isOccupied ? ' (Occupied)' : '');
        opt.disabled = isOccupied;

        roomSelect.appendChild(opt);
    }
}
</script>

</body>
</html>