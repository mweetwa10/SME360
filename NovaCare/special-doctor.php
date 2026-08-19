<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Receptionist') {
    header("Location: login.php");
    exit;
}

$receptionist_id = $_SESSION['user_id'];

$doctors = [];
$res = mysqli_query($conn, "
    SELECT doctor_id, full_name, specialization, phone, email
    FROM special_doctors
    ORDER BY full_name ASC
");
while ($row = mysqli_fetch_assoc($res)) {
    $doctors[] = $row;
}

$queue = [];
$res = mysqli_query($conn, "
    SELECT rq.queue_id, rq.patient_id, rq.check_in_time, rq.status,
           p.full_name, p.nrc
    FROM reception_queue rq
    INNER JOIN patients p ON p.patient_id = rq.patient_id
    WHERE DATE(rq.check_in_time) = CURDATE()
    ORDER BY rq.check_in_time ASC
");
while ($row = mysqli_fetch_assoc($res)) {
    $queue[] = $row;
}

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Assign Specialist</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }
header { background:#0f4c81; color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; }
.container { display:flex; min-height:calc(100vh - 60px); }
.sidebar { width:240px; background:#fff; box-shadow:2px 0 10px rgba(0,0,0,.05); padding-top:20px; }
.sidebar ul { list-style:none; }
.sidebar ul li { padding:15px 25px; border-left:4px solid transparent; transition:.3s; }
.sidebar ul li:hover, .sidebar ul li.active { background:#f0f6ff; border-left:4px solid #0f4c81; }
.sidebar ul li a { color:#333; text-decoration:none; display:block; }
.main { flex:1; padding:30px; }
.card { background:#fff; padding:25px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,.05); margin-bottom:25px; }
.section-title { color:#0f4c81; margin-bottom:18px; font-size:16px; font-weight:600; }

.search-row { display:flex; gap:10px; }
.search-row input { flex:1; padding:10px 14px; border:1px solid #ccc; border-radius:8px; font-size:14px; }
.btn { padding:10px 18px; border:none; border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; }
.btn-primary { background:#0f4c81; color:#fff; }
.btn-primary:hover { background:#0d3f6e; }
.btn-success { background:#16a34a; color:#fff; }
.btn-success:hover { background:#15803d; }
.btn-sm { padding:6px 12px; font-size:13px; border-radius:6px; border:none; cursor:pointer; font-weight:600; color:#fff; }

#patient_info { display:none; margin-top:15px; background:#eaf3ff; border:1px solid #b3d4f5; border-radius:8px; padding:14px 18px; }
#patient_info h4 { color:#0f4c81; margin-bottom:6px; }
#patient_info p { font-size:13px; color:#555; margin:2px 0; }

.doctors-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
.doctor-card { border:1px solid #e0e8f0; border-radius:10px; padding:16px; background:#f9fbff; }
.doctor-card h4 { color:#0f4c81; margin-bottom:4px; font-size:15px; }
.doctor-card .spec { font-size:13px; color:#666; margin-bottom:12px; }
.doctor-actions { display:flex; gap:8px; flex-wrap:wrap; }

table { width:100%; border-collapse:collapse; }
th, td { padding:11px 13px; border-bottom:1px solid #eee; font-size:13px; text-align:left; }
th { background:#0f4c81; color:#fff; }
tr:hover td { background:#f5f9ff; }
.badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; }
.badge-waiting  { background:#fef9c3; color:#854d0e; }
.badge-nurse    { background:#dcfce7; color:#166534; }
.badge-complete { background:#e0f2fe; color:#0c4a6e; }

.alert { padding:12px 16px; border-radius:8px; margin-bottom:18px; font-size:14px; }
.alert-success { background:#dcfce7; color:#166534; }
.alert-error   { background:#fee2e2; color:#991b1b; }
</style>
</head>
<body>

<header>
    <h2>MediPulse</h2>
    <span>Reception — Specialist Assignment</span>
</header>

<div class="container">

<nav class="sidebar">
    <ul>
        <li><a href="reception-dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
        <li class="active"><a href="assign-specialist.php"><i class="fas fa-user-md"></i> Assign Specialist</a></li>

    </ul>
</nav>

<div class="main">

    <div id="alertBox"></div>

    <div class="card">
        <h3 class="section-title"><i class="fas fa-search"></i> Step 1 — Find Patient</h3>
        <div class="search-row">
            <input type="text" id="patient_search" placeholder="Enter Patient ID or NRC">
            <button class="btn btn-primary" onclick="searchPatient()">Search</button>
        </div>

        <div id="patient_info">
            <h4 id="p_name"></h4>
            <p><b>ID:</b> <span id="p_id"></span></p>
            <p><b>NRC:</b> <span id="p_nrc"></span></p>
            <input type="hidden" id="patient_id">
            <div style="margin-top:12px;">
                <button class="btn btn-success" onclick="checkInPatient()">
                    <i class="fas fa-door-open"></i> Check In Patient
                </button>
            </div>
        </div>
    </div>

    <div class="card">
        <h3 class="section-title"><i class="fas fa-user-md"></i> Step 2 — Assign Specialist & Contact</h3>

        <?php if (empty($doctors)): ?>
            <p style="color:#888;font-size:14px;">No special doctors found.</p>
        <?php else: ?>
        <div class="doctors-grid">
            <?php foreach ($doctors as $d): ?>
            <div class="doctor-card">
                <h4><?= e($d['full_name']) ?></h4>
                <p class="spec"><i class="fas fa-briefcase-medical"></i> <?= e($d['specialization']) ?></p>
                <div class="doctor-actions">
                    <a href="tel:<?= e($d['phone']) ?>">
                        <button class="btn-sm" style="background:#16a34a;">
                            <i class="fas fa-phone"></i> Call
                        </button>
                    </a>
                    <a href="mailto:<?= e($d['email']) ?>?subject=Patient Assignment">
                        <button class="btn-sm" style="background:#3b82f6;">
                            <i class="fas fa-envelope"></i> Email
                        </button>
                    </a>
                    <button class="btn-sm" style="background:#0f4c81;"
                        onclick="assignDoctor('<?= e($d['doctor_id']) ?>', '<?= e(addslashes($d['full_name'])) ?>')">
                        <i class="fas fa-user-plus"></i> Assign
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="section-title"><i class="fas fa-stethoscope"></i> Step 3 — Send to Nurse for Vitals</h3>
        <p style="font-size:13px;color:#666;margin-bottom:14px;">
            Once a specialist is assigned, send the patient to the nurse station to have their vitals recorded before seeing the doctor.
        </p>
        <button class="btn btn-success" onclick="sendToNurse()">
            <i class="fas fa-arrow-right"></i> Send Patient to Nurse
        </button>
    </div>

    <div class="card">
        <h3 class="section-title"><i class="fas fa-list-ul"></i> Today's Reception Queue</h3>

        <?php if (empty($queue)): ?>
            <p style="color:#aaa;font-size:13px;">No patients checked in today.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Patient ID</th>
                    <th>Name</th>
                    <th>NRC</th>
                    <th>Check-in Time</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queue as $i => $row):
                    $badgeClass = match($row['status']) {
                        'sent_to_nurse' => 'badge-nurse',
                        'complete'      => 'badge-complete',
                        default         => 'badge-waiting'
                    };
                    $label = match($row['status']) {
                        'sent_to_nurse' => 'Sent to Nurse',
                        'complete'      => 'Complete',
                        default         => 'Waiting'
                    };
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($row['patient_id']) ?></td>
                    <td><?= e($row['full_name']) ?></td>
                    <td><?= e($row['nrc']) ?></td>
                    <td><?= date('H:i', strtotime($row['check_in_time'])) ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= $label ?></span></td>
                    <td>
                        <?php if ($row['status'] === 'waiting'): ?>
                        <button class="btn-sm" style="background:#0f4c81;"
                            onclick="loadPatient('<?= e($row['patient_id']) ?>', '<?= e(addslashes($row['full_name'])) ?>', '<?= e($row['nrc']) ?>')">
                            Select
                        </button>
                        <?php else: ?>
                        <span style="color:#aaa;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>
</div>

<script>
function showAlert(msg, type) {
    const box = document.getElementById('alertBox');
    box.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
    setTimeout(() => box.innerHTML = '', 4000);
}

function loadPatient(id, name, nrc) {
    document.getElementById('patient_id').value = id;
    document.getElementById('p_id').innerText   = id;
    document.getElementById('p_name').innerText = name;
    document.getElementById('p_nrc').innerText  = nrc;
    document.getElementById('patient_info').style.display = 'block';
    document.getElementById('patient_search').value = id;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function searchPatient() {
    const term = document.getElementById('patient_search').value.trim();
    if (!term) { showAlert('Enter a Patient ID or NRC.', 'error'); return; }

    fetch('find-patient.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'search=' + encodeURIComponent(term)
    })
    .then(r => r.json())
    .then(d => {
        if (d.status !== 'success') { showAlert('Patient not found.', 'error'); return; }
        loadPatient(d.patient.patient_id, d.patient.full_name, d.patient.nrc);
    });
}

function checkInPatient() {
    const patient_id = document.getElementById('patient_id').value;
    if (!patient_id) { showAlert('Search for a patient first.', 'error'); return; }

    fetch('checkin-patient.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'patient_id=' + encodeURIComponent(patient_id)
    })
    .then(r => r.json())
    .then(d => {
        showAlert(d.message, d.success ? 'success' : 'error');
        if (d.success) setTimeout(() => location.reload(), 1500);
    });
}

function assignDoctor(doctor_id, doctor_name) {
    const patient_id = document.getElementById('patient_id').value;
    if (!patient_id) { showAlert('Search for a patient first.', 'error'); return; }

    fetch('assign-specialist.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'patient_id=' + encodeURIComponent(patient_id) + '&doctor_id=' + encodeURIComponent(doctor_id)
    })
    .then(r => r.json())
    .then(d => {
        showAlert(d.message, d.success ? 'success' : 'error');
    });
}

function sendToNurse() {
    const patient_id = document.getElementById('patient_id').value;
    if (!patient_id) { showAlert('Search for a patient first.', 'error'); return; }

    fetch('send-to-nurse.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'patient_id=' + encodeURIComponent(patient_id)
    })
    .then(r => r.json())
    .then(d => {
        showAlert(d.message, d.success ? 'success' : 'error');
        if (d.success) setTimeout(() => location.reload(), 1500);
    });
}
</script>

</body>
</html>