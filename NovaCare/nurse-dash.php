<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Africa/Lusaka');

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Nurse') {
    header("Location: login.php");
    exit;
}

$nurseId   = $_SESSION['user_id'];
$nurseName = $_SESSION['full_name'];

$successMsg = $_SESSION['success'] ?? '';
$errorMsg   = $_SESSION['error']   ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$patients = [];
$patientResult = $conn->query("
    SELECT r.queue_id, p.patient_id, p.full_name, r.check_in_time, r.status
    FROM reception_queue r
    INNER JOIN patients p ON r.patient_id = p.patient_id
    WHERE r.status = 'Waiting'
    ORDER BY r.check_in_time ASC
");
if ($patientResult) {
    while ($row = $patientResult->fetch_assoc()) $patients[] = $row;
}

$today       = date('Y-m-d');
$currentTime = date('H:i:s');

if ($currentTime >= '07:01:00' && $currentTime <= '12:00:00') {
    $currentShift = 'Morning';
} elseif ($currentTime >= '12:01:00' && $currentTime <= '19:00:00') {
    $currentShift = 'Afternoon';
} else {
    $currentShift = 'Night';
}

$generalDoctors = [];
$stmt = $conn->prepare("
    SELECT d.doctor_id, d.full_name, d.specialization
    FROM doctors d
    INNER JOIN doctor_weekly_schedules s ON d.doctor_id = s.doctor_id
    WHERE s.schedule_date = ?
      AND s.shift_type    = ?
      AND d.type          = 'general'
    ORDER BY d.full_name ASC
");
$stmt->bind_param("ss", $today, $currentShift);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $generalDoctors[] = $row;
$stmt->close();

$specialists = [];
$stmt = $conn->prepare("
    SELECT d.doctor_id, d.full_name, d.specialization
    FROM doctors d
    INNER JOIN doctor_weekly_schedules s ON d.doctor_id = s.doctor_id
    WHERE s.schedule_date = ?
      AND s.shift_type    = ?
      AND d.type          = 'specialist'
    ORDER BY d.full_name ASC
");
$stmt->bind_param("ss", $today, $currentShift);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $specialists[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Nurse Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }

header {
    background:#0f4c81; color:#fff;
    padding:15px 30px;
    display:flex; justify-content:space-between; align-items:center;
}
header h1 { font-size:20px; }

.container { display:flex; min-height:calc(100vh - 60px); }

.sidebar { width:240px; background:#fff; box-shadow:2px 0 10px rgba(0,0,0,.05); padding-top:20px; }
.sidebar ul { list-style:none; }
.sidebar ul li { border-left:4px solid transparent; transition:.25s; }
.sidebar ul li a {
    text-decoration:none; color:#333;
    display:flex; align-items:center; gap:10px;
    padding:14px 22px; font-size:14px;
}
.sidebar ul li:hover { background:#f0f6ff; }
.sidebar ul li:hover a { color:#0f4c81; }
.sidebar ul li.active { background:#eaf3ff; border-left-color:#0f4c81; }
.sidebar ul li.active a { color:#0f4c81; font-weight:600; }
.sidebar ul li i { width:20px; text-align:center; color:#0f4c81; font-size:15px; }

.main-content { flex:1; padding:30px; }
.welcome { margin-bottom:20px; }
.welcome h2 { font-size:22px; }
.welcome p  { font-size:14px; color:#666; margin-top:4px; }

.flash {
    padding:12px 18px; border-radius:6px; margin-bottom:20px;
    font-size:14px; font-weight:500; display:flex; align-items:center; gap:10px;
}
.flash.success { background:#e6f9f0; color:#1a7a4a; border:1px solid #a3d9bc; }
.flash.error   { background:#fdecea; color:#c0392b; border:1px solid #f5b7b1; }

.card {
    background:#fff; padding:25px; border-radius:10px;
    box-shadow:0 8px 20px rgba(0,0,0,.05); margin-bottom:25px;
}
.card h3 {
    font-size:16px; color:#0f4c81;
    margin-bottom:16px; padding-bottom:10px;
    border-bottom:1px solid #eef0f5;
}

table { width:100%; border-collapse:collapse; }
th, td { padding:12px; font-size:14px; border-bottom:1px solid #eee; text-align:left; vertical-align:middle; }
th { background:#0f4c81; color:#fff; }
tbody tr { cursor:pointer; transition:background .15s; }
tbody tr:hover td { background:#f0f6ff; }
.status-badge { font-weight:600; color:#1a7a4a; }

.vitals-form { display:none; margin-top:25px; border-top:2px solid #eef0f5; padding-top:24px; }
.vitals-form h3 { font-size:16px; color:#0f4c81; margin-bottom:16px; }

.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; }
.form-group { display:flex; flex-direction:column; }
.form-group label { font-size:13px; font-weight:600; margin-bottom:5px; color:#444; }
.form-group input,
.form-group select,
.form-group textarea {
    width:100%; padding:10px; border-radius:6px;
    border:1px solid #ccc; font-size:14px;
    transition:border-color .2s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus { outline:none; border-color:#1fa2ff; }
.form-group input[readonly] { background:#f5f7fa; cursor:default; }
textarea { resize:none; }

.doctor-type-tabs { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.dtype-btn {
    padding:8px 20px; border-radius:6px;
    border:2px solid #0f4c81; background:#fff;
    color:#0f4c81; font-size:13px; font-weight:600;
    cursor:pointer; transition:.2s;
}
.dtype-btn.active { background:#0f4c81; color:#fff; }
.dtype-btn:hover:not(.active) { background:#eaf3ff; }

.doctor-panel { display:none; }
.doctor-panel.active { display:block; }

.no-doctor { font-size:13px; color:#c0392b; margin-top:6px; display:block; }

.submit-btn {
    margin-top:20px; background:#0f4c81; color:#fff;
    border:none; padding:12px 24px; border-radius:6px;
    font-size:14px; font-weight:600; cursor:pointer; transition:.2s;
}
.submit-btn:hover { background:#0c3c66; }
</style>
</head>
<body>

<header>
    <h1><i class="fas fa-heartbeat" style="margin-right:8px;"></i>MediPulse</h1>
    <span>Logged in as: <strong><?= htmlspecialchars($nurseName) ?></strong></span>
</header>

<div class="container">

    <nav class="sidebar">
        <ul>
            <li class="active"><a href="nurse-dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="ward.php"><i class="fas fa-procedures"></i> Ward Management</a></li>
            <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>

    <main class="main-content">

        <div class="welcome">
            <h2>Nurse Dashboard</h2>
            <p>Select a patient to record vitals and assign a doctor</p>
        </div>

        <?php if ($successMsg): ?>
            <div class="flash success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="flash error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <div class="card">
            <h3><i class="fas fa-users" style="margin-right:8px;"></i>Patients Waiting</h3>

            <table id="nurse-queue">
                <thead>
                    <tr>
                        <th>Patient ID</th>
                        <th>Name</th>
                        <th>Check-in Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($patients)): ?>
                    <?php foreach ($patients as $p): ?>
                    <tr data-patientid="<?= htmlspecialchars($p['patient_id']) ?>">
                        <td><?= htmlspecialchars($p['patient_id']) ?></td>
                        <td><?= htmlspecialchars($p['full_name']) ?></td>
                        <td><?= htmlspecialchars($p['check_in_time']) ?></td>
                        <td class="status-badge"><?= htmlspecialchars($p['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align:center;color:#666;padding:20px;">No patients currently waiting</td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            
            <div class="vitals-form" id="vitalsForm">
                <h3><i class="fas fa-heartbeat" style="margin-right:8px;"></i>Record Vitals &amp; Assign Doctor</h3>

                <form method="POST" action="save-vitals.php" id="vitalsFormEl">

                    <div class="form-grid">

                        <div class="form-group">
                            <label>Patient ID</label>
                            <input type="text" name="patient_id" id="formPatientID" readonly>
                        </div>

                        <div class="form-group">
                            <label>Temperature (°C) <span style="color:red">*</span></label>
                            <input type="number" step="0.1" name="temperature" placeholder="e.g. 36.6" required>
                        </div>

                        <div class="form-group">
                            <label>Blood Pressure <span style="color:red">*</span></label>
                            <input type="text" name="blood_pressure" placeholder="120/80" required>
                        </div>

                        <div class="form-group">
                            <label>Pulse Rate (bpm) <span style="color:red">*</span></label>
                            <input type="number" name="pulse" placeholder="e.g. 72" required>
                        </div>

                        <div class="form-group">
                            <label>Respiratory Rate (breaths/min)</label>
                            <input type="number" name="respiratory_rate" placeholder="e.g. 16">
                        </div>

                        <div class="form-group">
                            <label>Weight (kg)</label>
                            <input type="number" step="0.1" name="weight" placeholder="e.g. 70.5">
                        </div>

                    </div>

                    <div class="form-group" style="margin-top:15px;">
                        <label>Additional Notes</label>
                        <textarea name="notes" rows="3" placeholder="Any observations..."></textarea>
                    </div>

                
                    <div style="margin-top:24px;">
                        <label style="font-size:13px;font-weight:600;color:#444;display:block;margin-bottom:10px;">
                            Assign To
                        </label>

                        <div class="doctor-type-tabs">
                            <button type="button" class="dtype-btn active" onclick="switchDoctorType('general', this)">
                                <i class="fas fa-user-md"></i> General Doctor
                            </button>
                            <button type="button" class="dtype-btn" onclick="switchDoctorType('specialist', this)">
                                <i class="fas fa-stethoscope"></i> Specialist
                            </button>
                        </div>

                        <input type="hidden" name="doctor_id"    id="hiddenDoctorId"    value="">
                        <input type="hidden" name="doctor_type"  id="hiddenDoctorType"  value="general">

                        
                        <div class="doctor-panel active" id="panel-general">
                            <div class="form-group">
                                <select id="select-general" onchange="syncDoctor()">
                                    <?php if (!empty($generalDoctors)): ?>
                                        <option value="">-- Select General Doctor --</option>
                                        <?php foreach ($generalDoctors as $d): ?>
                                        <option value="<?= htmlspecialchars($d['doctor_id']) ?>">
                                            Dr. <?= htmlspecialchars($d['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="">No general doctors on <?= htmlspecialchars($currentShift) ?> shift</option>
                                    <?php endif; ?>
                                </select>
                                <?php if (empty($generalDoctors)): ?>
                                    <span class="no-doctor">
                                        <i class="fas fa-exclamation-circle"></i>
                                        No general doctors scheduled for the current shift.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        
                        <div class="doctor-panel" id="panel-specialist">
                            <div class="form-group">
                                <select id="select-specialist" onchange="syncDoctor()">
                                    <?php if (!empty($specialists)): ?>
                                        <option value="">-- Select Specialist --</option>
                                        <?php foreach ($specialists as $d): ?>
                                        <option value="<?= htmlspecialchars($d['doctor_id']) ?>">
                                            Dr. <?= htmlspecialchars($d['full_name']) ?>
                                            <?php if ($d['specialization']): ?>
                                                — <?= htmlspecialchars($d['specialization']) ?>
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="">No specialists on <?= htmlspecialchars($currentShift) ?> shift</option>
                                    <?php endif; ?>
                                </select>
                                <?php if (empty($specialists)): ?>
                                    <span class="no-doctor">
                                        <i class="fas fa-exclamation-circle"></i>
                                        No specialists scheduled for the current shift.
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>

                    <button type="submit" class="submit-btn">
                        <i class="fas fa-check-circle"></i> Save Vitals &amp; Assign
                    </button>

                </form>
            </div>

        </div>
    </main>
</div>

<script>

let activeDoctorType = 'general';


document.querySelectorAll('#nurse-queue tbody tr[data-patientid]').forEach(row => {
    row.addEventListener('click', () => {
        document.getElementById('formPatientID').value = row.dataset.patientid;
        const form = document.getElementById('vitalsForm');
        form.style.display = 'block';
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

function syncDoctor() {
    const selectId = (activeDoctorType === 'general') ? 'select-general' : 'select-specialist';
    document.getElementById('hiddenDoctorId').value = document.getElementById(selectId).value;
}

function switchDoctorType(type, btn) {
    activeDoctorType = type;

    
    document.querySelectorAll('.dtype-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    
    document.querySelectorAll('.doctor-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + type).classList.add('active');

    
    document.getElementById('hiddenDoctorType').value = type;

    
    syncDoctor();
}
</script>

</body>
</html>