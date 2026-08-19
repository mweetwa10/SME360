<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

// AJAX duplicate check
if (isset($_GET['check_duplicate'])) {
    header('Content-Type: application/json');
    $field = $_GET['field'];   // 'email' or 'phone'
    $value = trim($_GET['value']);
    $role  = trim($_GET['role']);

    $tables = ['Doctor'=>'doctors','Nurse'=>'nurses','Pharmacist'=>'pharmacists','Lab Staff'=>'lab_staff','Receptionist'=>'receptionists'];
    $table  = $tables[$role] ?? null;
    $col    = ($field === 'email') ? 'email' : 'phone';

    if (!$table || !in_array($col, ['email','phone'])) {
        echo json_encode(['exists' => false]);
        exit;
    }

    $stmt = $conn->prepare("SELECT $col FROM $table WHERE $col = ?");
    $stmt->bind_param("s", $value);
    $stmt->execute();
    echo json_encode(['exists' => $stmt->get_result()->num_rows > 0]);
    exit;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$totalDoctors = $conn->query("SELECT COUNT(*) AS total FROM doctors")->fetch_assoc()['total'];
$totalNurses = $conn->query("SELECT COUNT(*) AS total FROM nurses")->fetch_assoc()['total'];
$totalPatients = $conn->query("SELECT COUNT(*) AS total FROM patients")->fetch_assoc()['total'];
$appointmentsToday = $conn->query("
    SELECT COUNT(*) AS total FROM appointments WHERE DATE(scheduled_date) = CURDATE()
")->fetch_assoc()['total'];

$monthlyPatients = [];
$monthlyAppointments = [];
$months = [];

for ($i = 1; $i <= 12; $i++) {
    $months[] = date("M", mktime(0, 0, 0, $i, 1));
    $monthlyPatients[] = $conn->query("
        SELECT COUNT(*) AS total FROM patients
        WHERE MONTH(created_at) = $i AND YEAR(created_at) = YEAR(CURDATE())
    ")->fetch_assoc()['total'];
    $monthlyAppointments[] = $conn->query("
        SELECT COUNT(*) AS total FROM appointments
        WHERE MONTH(created_at) = $i AND YEAR(created_at) = YEAR(CURDATE())
    ")->fetch_assoc()['total'];
}

$doctorAppointments = [];
$doctorNames = [];
$doctorChart = $conn->query("
    SELECT doctors.full_name, COUNT(appointments.appointment_id) AS total
    FROM doctors
    LEFT JOIN appointments ON doctors.doctor_id = appointments.doctor_id
    GROUP BY doctors.doctor_id
    ORDER BY total DESC
    LIMIT 6
");
while ($row = $doctorChart->fetch_assoc()) {
    $doctorNames[] = $row['full_name'];
    $doctorAppointments[] = $row['total'];
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role'])) {
    $role             = trim($_POST['role']);
    $full_name        = trim($_POST['full_name']);
    $email            = trim($_POST['email']);
    $phone            = trim($_POST['phone']);
    $gender           = trim($_POST['gender']);
    $dob              = $_POST['dob'];
    $nrc              = trim($_POST['nrc']);
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $nok_name         = trim($_POST['nok_name']);
    $nok_phone        = trim($_POST['nok_phone']);

    if ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        $password_hashed = password_hash($password, PASSWORD_DEFAULT);
        $year = date("Y");

        switch ($role) {
            case "Doctor":       $table = "doctors";       $idPrefix = "DOC"; $idColumn = "doctor_id";       break;
            case "Nurse":        $table = "nurses";        $idPrefix = "NUR"; $idColumn = "nurse_id";        break;
            case "Pharmacist":   $table = "pharmacists";   $idPrefix = "PHA"; $idColumn = "pharmacist_id";   break;
            case "Lab Staff":    $table = "lab_staff";     $idPrefix = "LAB"; $idColumn = "lab_staff_id";    break;
            case "Receptionist": $table = "receptionists"; $idPrefix = "REC"; $idColumn = "receptionist_id"; break;
            default: $error = "Invalid role selected!"; $table = ''; break;
        }

        if ($table && empty($error)) {
            $checkNrc = $conn->prepare("SELECT nrc FROM $table WHERE nrc = ?");
            $checkNrc->bind_param("s", $nrc);
            $checkNrc->execute();

            if ($checkNrc->get_result()->num_rows > 0) {
                $error = "NRC already exists!";
            } else {
                $checkEmail = $conn->prepare("SELECT email FROM $table WHERE email = ?");
                $checkEmail->bind_param("s", $email);
                $checkEmail->execute();

                if ($checkEmail->get_result()->num_rows > 0) {
                    $error = "Email already exists!";
                } else {
                    $lastIdQuery = $conn->query("SELECT $idColumn FROM $table ORDER BY $idColumn DESC LIMIT 1");
                    $seq = ($lastIdQuery && $lastIdQuery->num_rows > 0)
                        ? intval(substr($lastIdQuery->fetch_assoc()[$idColumn], -3)) + 1
                        : 1;
                    $autoId = $idPrefix . $year . str_pad($seq, 3, "0", STR_PAD_LEFT);

                    if ($role === 'Doctor') {
                        $doc_type       = trim($_POST['doc_type'] ?? 'general');
                        $specialization = trim($_POST['specialization'] ?? '');
                        $stmt = $conn->prepare("
                            INSERT INTO $table ($idColumn, full_name, email, phone, gender, dob, nrc, password, nok_full_name, nok_phone, type, specialization, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->bind_param("ssssssssssss", $autoId, $full_name, $email, $phone, $gender, $dob, $nrc, $password_hashed, $nok_name, $nok_phone, $doc_type, $specialization);
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO $table ($idColumn, full_name, email, phone, gender, dob, nrc, password, nok_full_name, nok_phone, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->bind_param("ssssssssss", $autoId, $full_name, $email, $phone, $gender, $dob, $nrc, $password_hashed, $nok_name, $nok_phone);
                    }

                    if ($stmt->execute()) {
                        $msg = "$role added successfully with ID $autoId";
                    } else {
                        $error = ($conn->errno == 1062) ? "Duplicate entry detected." : "Error: " . $stmt->error;
                    }
                    $stmt->close();
                }
                $checkEmail->close();
            }
            $checkNrc->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse | Admin Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }
header { background:#0f4c81; color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; }
.header-left { display:flex; align-items:center; gap:12px; }
.header-left h1 { font-size:22px; }
.container { display:flex; min-height:calc(100vh - 70px); }
.sidebar { width:250px; background:#fff; box-shadow:2px 0 10px rgba(0,0,0,.05); padding-top:20px; }
.sidebar ul { list-style:none; }
.sidebar ul li { padding:15px 25px; font-size:14px; border-left:4px solid transparent; transition:.3s; }
.sidebar ul li:hover, .sidebar ul li.active { background:#f0f6ff; border-left:4px solid #0f4c81; color:#0f4c81; font-weight:600; }
.sidebar ul li a { text-decoration:none; color:inherit; display:block; }
.sidebar i { margin-right:10px; }
.main-content { flex:1; padding:30px; }
.welcome { margin-bottom:25px; }
.welcome h2 { font-size:24px; margin-bottom:5px; }
.welcome p { color:#666; }
.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px; margin-bottom:30px; }
.card { background:#fff; padding:22px; border-radius:14px; box-shadow:0 8px 20px rgba(0,0,0,.05); }
.card h3 { font-size:15px; color:#555; margin-bottom:10px; }
.card span { font-size:32px; font-weight:700; color:#0f4c81; }
.quick-btn { background:#0f4c81; color:#fff; border:none; padding:14px 22px; border-radius:8px; cursor:pointer; font-size:15px; margin-bottom:25px; transition:.3s; }
.quick-btn:hover { background:#0c3d67; }
#addStaffForm { display:none; background:#fff; padding:25px; border-radius:14px; box-shadow:0 8px 20px rgba(0,0,0,.05); margin-bottom:30px; }
#addStaffForm.active { display:block; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:18px; }
.form-group label { display:block; margin-bottom:6px; font-size:13px; font-weight:600; }
.form-group input, .form-group select { width:100%; padding:10px; border:1px solid #ccc; border-radius:8px; font-size:14px; }
.submit-btn { margin-top:20px; padding:12px 20px; background:#1fa2ff; color:#fff; border:none; border-radius:8px; cursor:pointer; }
.submit-btn:hover { background:#1685d9; }
.alert-success { margin-bottom:15px; padding:12px; border-radius:8px; background:#d4edda; color:#155724; }
.alert-error { margin-bottom:15px; padding:12px; border-radius:8px; background:#f8d7da; color:#721c24; }
.toggle-password { position:absolute; right:12px; top:50%; transform:translateY(-50%); cursor:pointer; }
.password-wrapper { position:relative; }
.chart-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(350px,1fr)); gap:25px; margin-top:30px; }
.chart-card { background:#fff; padding:20px; border-radius:14px; box-shadow:0 8px 20px rgba(0,0,0,.05); }
.chart-card h3 { margin-bottom:20px; color:#0f4c81; }
canvas { width:100% !important; height:320px !important; }
.field-error { border-color:#e74c3c !important; }
.field-ok    { border-color:#27ae60 !important; }
.field-hint  { font-size:11px; margin-top:-14px; margin-bottom:10px; display:none; }
.field-hint.error { color:#e74c3c; display:block; }
.field-hint.ok    { color:#27ae60; display:block; }
input:disabled, select:disabled { background:#f0f0f0; cursor:not-allowed; opacity:.6; }
.submit-btn:disabled { background:#aaa; cursor:not-allowed; }
</style>
</head>
<body>
<header>
    <div class="header-left"><h1>MediPulse</h1></div>
    <div>Logged in as: <strong><?= isset($_SESSION['full_name']) ? htmlspecialchars($_SESSION['full_name']) : 'Administrator' ?></strong></div>
</header>
<div class="container">
    <nav class="sidebar">
        <ul>
            <li class="active"><a href="admin-dash.php"><i class="fa-solid fa-gauge"></i>Dashboard</a></li>
            <li><a href="manage-staff.php"><i class="fa-solid fa-user-doctor"></i>Manage Staff</a></li>
            <li><a href="doctor-schedule.php"><i class="fa-solid fa-calendar-days"></i>Doctor Schedules</a></li>
            <li><a href="reports.php"><i class="fa-solid fa-chart-line"></i>Reports</a></li>
            <li><a href="login.php"><i class="fa-solid fa-right-from-bracket"></i>Logout</a></li>
        </ul>
    </nav>
    <main class="main-content">
        <div class="welcome">
            <h2>Admin Dashboard</h2>
            <p>Manage hospital staff, statistics and activities</p>
        </div>
        <div class="cards">
            <div class="card"><h3>Total Doctors</h3><span><?= $totalDoctors ?></span></div>
            <div class="card"><h3>Total Nurses</h3><span><?= $totalNurses ?></span></div>
            <div class="card"><h3>Total Patients</h3><span><?= $totalPatients ?></span></div>
            <div class="card"><h3>Appointments Today</h3><span><?= $appointmentsToday ?></span></div>
        </div>
        <?php if ($msg): ?><div class="alert-success"><?= $msg ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert-error"><?= $error ?></div><?php endif; ?>
        <button class="quick-btn" onclick="toggleStaffForm()"><i class="fa-solid fa-user-plus"></i> Add New Staff Member</button>
        <div id="addStaffForm">
            <h3 style="margin-bottom:20px;color:#0f4c81;">Add New Staff Member</h3>
            <form method="POST" id="staffForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Role</label>
                        <select id="staffRole" name="role" required>
                            <option value="">-- Select Role --</option>
                            <option>Doctor</option>
                            <option>Nurse</option>
                            <option>Pharmacist</option>
                            <option>Lab Staff</option>
                            <option>Receptionist</option>
                        </select>
                        <span class="field-hint" id="hint-role"></span>
                    </div>
                    <div class="form-group">
                        <label>Auto Generated ID</label>
                        <input type="text" id="staffId" readonly>
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" id="f-full_name" name="full_name" disabled required>
                        <span class="field-hint" id="hint-full_name"></span>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="f-email" name="email" disabled required>
                        <span class="field-hint" id="hint-email"></span>
                    </div>
                    <div class="form-group">
                        <label>Phone (10 digits)</label>
                        <input type="text" id="f-phone" name="phone" maxlength="10" disabled required>
                        <span class="field-hint" id="hint-phone"></span>
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select id="f-gender" name="gender" disabled required>
                            <option value="">-- Select Gender --</option>
                            <option>Male</option>
                            <option>Female</option>
                        </select>
                        <span class="field-hint" id="hint-gender"></span>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" id="f-dob" name="dob" disabled required max="<?= date('Y-m-d') ?>">
                        <span class="field-hint" id="hint-dob"></span>
                    </div>
                    <div class="form-group">
                        <label>NRC</label>
                        <input type="text" id="f-nrc" name="nrc" disabled required>
                        <span class="field-hint" id="hint-nrc"></span>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" disabled required>
                            <span class="toggle-password" onclick="togglePassword('password')">👁️</span>
                        </div>
                        <span class="field-hint" id="hint-password"></span>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" disabled required>
                            <span class="toggle-password" onclick="togglePassword('confirm_password')">👁️</span>
                        </div>
                        <span class="field-hint" id="hint-confirm_password"></span>
                    </div>
                    <div class="form-group">
                        <label>Next of Kin Name</label>
                        <input type="text" id="f-nok_name" name="nok_name" disabled required>
                        <span class="field-hint" id="hint-nok_name"></span>
                    </div>
                    <div class="form-group">
                        <label>Next of Kin Phone (10 digits)</label>
                        <input type="text" id="f-nok_phone" name="nok_phone" maxlength="10" disabled required>
                        <span class="field-hint" id="hint-nok_phone"></span>
                    </div>
                    <!-- Doctor-only fields -->
                    <div class="form-group" id="doctorTypeField" style="display:none;">
                        <label>Doctor Type</label>
                        <select name="doc_type" id="docTypeSelect">
                            <option value="general">General</option>
                            <option value="specialist">Specialist</option>
                        </select>
                    </div>
                    <div class="form-group" id="specializationField" style="display:none;">
                        <label>Specialization</label>
                        <input type="text" name="specialization" id="specializationInput" placeholder="e.g. Cardiologist">
                        <span class="field-hint" id="hint-specialization"></span>
                    </div>
                </div>
                <button class="submit-btn" id="submitBtn" type="submit" disabled>Add Staff</button>
            </form>
        </div>
        <div class="chart-grid">
            <div class="chart-card"><h3>Monthly Patient Registrations</h3><canvas id="patientsChart"></canvas></div>
            <div class="chart-card"><h3>Monthly Appointments</h3><canvas id="appointmentsChart"></canvas></div>
            <div class="chart-card"><h3>Doctor Appointment Activity</h3><canvas id="doctorChart"></canvas></div>
        </div>
    </main>
</div>
<script>
// ── State ──────────────────────────────────────────────────────────────
const valid = {};  // tracks which field keys are passing

// ── Helpers ────────────────────────────────────────────────────────────
function hint(id, msg, ok) {
    const el = document.getElementById('hint-' + id);
    if (!el) return;
    el.textContent = msg;
    el.className = 'field-hint ' + (ok ? 'ok' : 'error');
}
function clearHint(id) {
    const el = document.getElementById('hint-' + id);
    if (el) { el.textContent = ''; el.className = 'field-hint'; }
}
function markField(el, ok) {
    el.classList.toggle('field-ok',    ok);
    el.classList.toggle('field-error', !ok);
}
function enable(id) {
    const el = document.getElementById(id);
    if (el) el.disabled = false;
}
function getRole() { return document.getElementById('staffRole').value; }

function checkSubmit() {
    const role = getRole();
    const isDoctor = role === 'Doctor';
    const isSpecialist = isDoctor && document.getElementById('docTypeSelect').value === 'specialist';

    const required = ['role','full_name','email','phone','gender','dob','nrc','password','confirm_password','nok_name','nok_phone'];
    if (isSpecialist) required.push('specialization');

    const allOk = required.every(k => valid[k]);
    document.getElementById('submitBtn').disabled = !allOk;
}

// ── AJAX duplicate check ───────────────────────────────────────────────
async function checkDuplicate(field, value) {
    const role = getRole();
    if (!role || !value) return null;
    const res = await fetch(`?check_duplicate=1&field=${field}&value=${encodeURIComponent(value)}&role=${encodeURIComponent(role)}`);
    const data = await res.json();
    return data.exists;
}

// ── Role ───────────────────────────────────────────────────────────────
document.getElementById('staffRole').addEventListener('change', function () {
    const role = this.value;
    valid['role'] = !!role;
    markField(this, !!role);

    // Generate preview ID
    const year = new Date().getFullYear();
    const prefixes = { Doctor:'DOC', Nurse:'NUR', Pharmacist:'PHA', 'Lab Staff':'LAB', Receptionist:'REC' };
    document.getElementById('staffId').value = role ? (prefixes[role] || 'STA') + year + '###' : '';

    // Toggle doctor fields
    const isDoctor = role === 'Doctor';
    document.getElementById('doctorTypeField').style.display = isDoctor ? 'block' : 'none';
    if (!isDoctor) {
        document.getElementById('specializationField').style.display = 'none';
        document.getElementById('specializationInput').removeAttribute('required');
        delete valid['specialization'];
    } else {
        toggleSpecialization();
    }

    // Unlock full_name
    if (role) enable('f-full_name');
    checkSubmit();
});

// ── Full Name ──────────────────────────────────────────────────────────
document.getElementById('f-full_name').addEventListener('blur', function () {
    const ok = this.value.trim().length >= 2;
    valid['full_name'] = ok;
    markField(this, ok);
    hint('full_name', ok ? '✓ Looks good' : 'Enter a valid full name', ok);
    if (ok) enable('f-email');
    checkSubmit();
});

// ── Email ──────────────────────────────────────────────────────────────
document.getElementById('f-email').addEventListener('blur', async function () {
    const val = this.value.trim();
    const fmt = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
    if (!fmt) {
        valid['email'] = false;
        markField(this, false);
        hint('email', 'Enter a valid email address', false);
        checkSubmit();
        return;
    }
    hint('email', 'Checking...', true);
    const exists = await checkDuplicate('email', val);
    const ok = !exists;
    valid['email'] = ok;
    markField(this, ok);
    hint('email', ok ? '✓ Email available' : 'Email already in use', ok);
    if (ok) enable('f-phone');
    checkSubmit();
});

// ── Phone ──────────────────────────────────────────────────────────────
document.getElementById('f-phone').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
});
document.getElementById('f-phone').addEventListener('blur', async function () {
    const val = this.value.trim();
    if (val.length !== 10) {
        valid['phone'] = false;
        markField(this, false);
        hint('phone', 'Phone must be exactly 10 digits', false);
        checkSubmit();
        return;
    }
    hint('phone', 'Checking...', true);
    const exists = await checkDuplicate('phone', val);
    const ok = !exists;
    valid['phone'] = ok;
    markField(this, ok);
    hint('phone', ok ? '✓ Phone available' : 'Phone number already in use', ok);
    if (ok) enable('f-gender');
    checkSubmit();
});

// ── Gender ─────────────────────────────────────────────────────────────
document.getElementById('f-gender').addEventListener('change', function () {
    const ok = !!this.value;
    valid['gender'] = ok;
    markField(this, ok);
    hint('gender', ok ? '✓ Selected' : 'Please select a gender', ok);
    if (ok) enable('f-dob');
    checkSubmit();
});

// ── DOB ────────────────────────────────────────────────────────────────
document.getElementById('f-dob').addEventListener('change', function () {
    const ok = !!this.value;
    valid['dob'] = ok;
    markField(this, ok);
    hint('dob', ok ? '✓ Date selected' : 'Please select a date of birth', ok);
    if (ok) enable('f-nrc');
    checkSubmit();
});

// ── NRC ────────────────────────────────────────────────────────────────
document.getElementById('f-nrc').addEventListener('blur', function () {
    const ok = this.value.trim().length >= 4;
    valid['nrc'] = ok;
    markField(this, ok);
    hint('nrc', ok ? '✓ NRC entered' : 'Enter a valid NRC number', ok);
    if (ok) enable('password');
    checkSubmit();
});

// ── Password ───────────────────────────────────────────────────────────
document.getElementById('password').addEventListener('blur', function () {
    const ok = this.value.length >= 6;
    valid['password'] = ok;
    markField(this, ok);
    hint('password', ok ? '✓ Password ok' : 'Password must be at least 6 characters', ok);
    if (ok) enable('confirm_password');
    // Reset confirm if password changed
    valid['confirm_password'] = false;
    const cp = document.getElementById('confirm_password');
    cp.value = '';
    markField(cp, false);
    clearHint('confirm_password');
    checkSubmit();
});

// ── Confirm Password ───────────────────────────────────────────────────
document.getElementById('confirm_password').addEventListener('blur', function () {
    const ok = this.value === document.getElementById('password').value && this.value.length > 0;
    valid['confirm_password'] = ok;
    markField(this, ok);
    hint('confirm_password', ok ? '✓ Passwords match' : 'Passwords do not match', ok);
    if (ok) enable('f-nok_name');
    checkSubmit();
});

// ── NOK Name ───────────────────────────────────────────────────────────
document.getElementById('f-nok_name').addEventListener('blur', function () {
    const ok = this.value.trim().length >= 2;
    valid['nok_name'] = ok;
    markField(this, ok);
    hint('nok_name', ok ? '✓ Looks good' : 'Enter next of kin full name', ok);
    if (ok) enable('f-nok_phone');
    checkSubmit();
});

// ── NOK Phone ──────────────────────────────────────────────────────────
document.getElementById('f-nok_phone').addEventListener('input', function () {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
});
document.getElementById('f-nok_phone').addEventListener('blur', function () {
    const ok = this.value.trim().length === 10;
    valid['nok_phone'] = ok;
    markField(this, ok);
    hint('nok_phone', ok ? '✓ Phone number valid' : 'Phone must be exactly 10 digits', ok);
    checkSubmit();
});

// ── Specialization ─────────────────────────────────────────────────────
document.getElementById('specializationInput').addEventListener('blur', function () {
    const visible = document.getElementById('specializationField').style.display !== 'none';
    if (!visible) return;
    const ok = this.value.trim().length >= 2;
    valid['specialization'] = ok;
    markField(this, ok);
    hint('specialization', ok ? '✓ Specialization entered' : 'Enter a specialization', ok);
    checkSubmit();
});

// ── Doctor type toggle ─────────────────────────────────────────────────
function toggleSpecialization() {
    const isSpecialist = document.getElementById('docTypeSelect').value === 'specialist';
    const field = document.getElementById('specializationField');
    const input = document.getElementById('specializationInput');
    field.style.display = isSpecialist ? 'block' : 'none';
    if (isSpecialist) {
        input.setAttribute('required', 'required');
    } else {
        input.removeAttribute('required');
        delete valid['specialization'];
        clearHint('specialization');
    }
    checkSubmit();
}
document.getElementById('docTypeSelect').addEventListener('change', toggleSpecialization);

// ── Misc ───────────────────────────────────────────────────────────────
function toggleStaffForm() {
    document.getElementById("addStaffForm").classList.toggle("active");
}

function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    field.type = field.type === "password" ? "text" : "password";
}

new Chart(document.getElementById('patientsChart'), {
    type: 'line',
    data: { labels: <?= json_encode($months) ?>, datasets: [{ label:'Patients', data: <?= json_encode($monthlyPatients) ?>, borderWidth:3, tension:.3, fill:false }] },
    options: { responsive:true }
});
new Chart(document.getElementById('appointmentsChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($months) ?>, datasets: [{ label:'Appointments', data: <?= json_encode($monthlyAppointments) ?>, borderWidth:1 }] },
    options: { responsive:true }
});
new Chart(document.getElementById('doctorChart'), {
    type: 'doughnut',
    data: { labels: <?= json_encode($doctorNames) ?>, datasets: [{ data: <?= json_encode($doctorAppointments) ?>, borderWidth:1 }] },
    options: { responsive:true }
});
</script>
</body>
</html>