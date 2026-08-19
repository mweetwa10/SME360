<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Africa/Lusaka');

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    $_SESSION['error'] = "You must be logged in as a doctor.";
    header("Location: login.php");
    exit;
}

$doctor_id  = $_SESSION['user_id'];
$doctorName = "Doctor";

$stmt = $conn->prepare("SELECT full_name FROM doctors WHERE doctor_id = ?");
$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$stmt->bind_result($doctorName);
$stmt->fetch();
$stmt->close();

// Auto-generate consultation ID
$consultation_id = "CON0001";
$result = $conn->query("SELECT consultation_id FROM consultations ORDER BY consultation_id DESC LIMIT 1");
if ($result && $result->num_rows > 0) {
    $lastId          = $result->fetch_assoc()['consultation_id'];
    $number          = intval(substr($lastId, 3)) + 1;
    $consultation_id = "CON" . str_pad($number, 4, '0', STR_PAD_LEFT);
}

// Patients waiting in queue
$patients = [];
$stmt = $conn->prepare("
    SELECT
        dq.queue_id,
        p.patient_id,
        p.full_name,
        dq.status,
        dq.assigned_at,
        v.temperature,
        v.blood_pressure,
        v.pulse,
        v.weight,
        v.notes AS vital_notes
    FROM doctor_queue dq
    INNER JOIN patients p ON dq.patient_id = p.patient_id
    LEFT JOIN nurse_vitals v ON v.patient_id = p.patient_id AND DATE(v.recorded_at) = CURDATE()
    WHERE dq.doctor_id = ? AND dq.status = 'Waiting'
    ORDER BY dq.assigned_at ASC
");
$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $patients[] = $row;
$stmt->close();

// Medicines
$medicines = [];
$result = $conn->query("SELECT medicine_id, name, dosage FROM medicines ORDER BY name ASC");
while ($row = $result->fetch_assoc()) $medicines[] = $row;

// Lab test types
$labTests = [];
$result = $conn->query("SELECT test_name FROM lab_test_types ORDER BY test_name ASC");
while ($row = $result->fetch_assoc()) $labTests[] = $row;

// Specialists (exclude self)
$specialists = [];
$stmt = $conn->prepare("
    SELECT doctor_id, full_name, specialization, email
    FROM doctors
    WHERE doctor_id != ? AND type = 'specialist'
    ORDER BY specialization ASC, full_name ASC
");
$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $specialists[] = $row;
$stmt->close();

// Group specialists by specialization for optgroups
$grouped = [];
foreach ($specialists as $sp) {
    $grouped[$sp['specialization']][] = $sp;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse | Doctor Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }

header { background:#0f4c81; color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; }
header h1 { font-size:22px; }

.container { display:flex; min-height:calc(100vh - 70px); }

.sidebar { width:240px; background:#fff; box-shadow:2px 0 10px rgba(0,0,0,.05); padding-top:20px; flex-shrink:0; }
.sidebar ul { list-style:none; }
.sidebar ul li { border-left:4px solid transparent; transition:.3s; }
.sidebar ul li a { display:flex; align-items:center; gap:10px; padding:15px 25px; text-decoration:none; color:#333; font-size:14px; }
.sidebar ul li:hover, .sidebar ul li.active { background:#f0f6ff; border-left-color:#0f4c81; }
.sidebar ul li.active a, .sidebar ul li:hover a { color:#0f4c81; font-weight:600; }

.main-content { flex:1; padding:30px; }

/* Cards / sections */
.section-card {
    background:#fff;
    padding:25px;
    border-radius:12px;
    margin-bottom:25px;
    box-shadow:0 8px 20px rgba(0,0,0,.05);
}
.section-title { margin-bottom:18px; color:#0f4c81; font-size:17px; }

/* Table */
table { width:100%; border-collapse:collapse; }
th, td { padding:12px; border-bottom:1px solid #eee; font-size:14px; text-align:left; }
th { background:#0f4c81; color:#fff; }
tr:hover td { background:#f9fbff; }

/* Inputs */
label { display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:#444; }
input, select, textarea {
    width:100%; padding:10px; border:1px solid #ccc;
    border-radius:6px; font-size:14px; color:#333;
    transition:border-color .2s;
}
input:focus, select:focus, textarea:focus { outline:none; border-color:#1fa2ff; }
input[readonly] { background:#f5f7fa; cursor:default; }
textarea { resize:none; }

.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:15px; }
.form-group { display:flex; flex-direction:column; }

/* Buttons */
.action-btn, .submit-btn {
    background:#1fa2ff; color:#fff; border:none;
    padding:10px 15px; border-radius:6px;
    cursor:pointer; font-weight:600; font-size:14px;
    transition:background .2s;
}
.action-btn:hover, .submit-btn:hover { background:#1685d9; }

/* Status */
.status { color:#e67e22; font-weight:600; }

/* Vitals */
.vitals-box { margin-bottom:20px; padding:15px; background:#f9fbff; border:1px solid #ddd; border-radius:10px; }
.vitals-box h4 { margin-bottom:12px; color:#0f4c81; }
.vitals-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px; }
.vital { padding:12px; border-radius:8px; text-align:center; font-size:13px; background:#ecf0f1; }
.vital.normal  { background:#dcfce7; color:#166534; }
.vital.warning { background:#fee2e2; color:#991b1b; }

/* Lab test tags */
#selected-tests { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; min-height:36px; }
.test-tag {
    display:inline-flex; align-items:center; gap:6px;
    background:#eaf3ff; border:1px solid #b3d4f5;
    color:#0f4c81; border-radius:20px; padding:5px 12px; font-size:13px;
}
.test-tag button {
    background:none; border:none; color:#c0392b;
    cursor:pointer; font-size:15px; padding:0; line-height:1; width:auto;
}

/* Referral section */
.referral-card {
    background:#fff;
    padding:25px;
    border-radius:12px;
    margin-bottom:25px;
    box-shadow:0 8px 20px rgba(0,0,0,.05);
    border-left:5px solid #7c3aed;
}
.referral-title { margin-bottom:18px; color:#7c3aed; font-size:17px; }
.referral-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:15px; align-items:end; }

.specialist-preview {
    display:none;
    margin-top:14px;
    background:#f5f0ff;
    border:1px solid #d8b4fe;
    border-radius:8px;
    padding:12px 16px;
    font-size:13px;
    color:#4c1d95;
    align-items:center;
    gap:10px;
}
.specialist-preview i { font-size:20px; color:#7c3aed; }
.sp-info strong { display:block; font-size:14px; }

.refer-btn {
    background:#7c3aed; color:#fff; border:none;
    padding:10px 18px; border-radius:6px;
    cursor:pointer; font-weight:600; width:100%;
    font-size:14px; transition:background .2s;
    margin-top:18px; max-width:260px;
}
.refer-btn:hover { background:#6d28d9; }
.refer-btn:disabled { background:#a78bfa; cursor:not-allowed; }

.referral-feedback {
    margin-top:14px; padding:10px 15px;
    border-radius:6px; font-size:13px; font-weight:600; display:none;
}
.referral-feedback.success { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.referral-feedback.error   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
</style>
</head>
<body>

<header>
    <h1>MediPulse</h1>
    <div>Logged in as <strong><?= htmlspecialchars($doctorName) ?></strong></div>
</header>

<div class="container">
    <nav class="sidebar">
        <ul>
            <li class="active"><a href="doctor-dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="schedule.php"><i class="fas fa-calendar-alt"></i> My Schedule</a></li>
            <li><a href="special-dash.php"><i class="fas fa-user-plus"></i> Referred Patients</a></li>
            <li><a href="my-patients.php"><i class="fas fa-user-injured"></i> My Patients</a></li>
            <li><a href="admit-patients.php"><i class="fas fa-procedures"></i> Admit Patients</a></li>
            <li><a href="referral.php"><i class="fas fa-file-medical"></i> Patient Referrals</a></li>
            <li><a href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="login.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>

    <main class="main-content">

        <!-- Patients Waiting -->
        <div class="section-card">
            <h3 class="section-title">Patients Waiting</h3>
            <table id="patientsTable">
                <thead>
                    <tr>
                        <th>Patient ID</th>
                        <th>Name</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patients as $row): ?>
                    <tr
                        data-id="<?= htmlspecialchars($row['patient_id']) ?>"
                        data-name="<?= htmlspecialchars($row['full_name']) ?>"
                        data-temp="<?= htmlspecialchars($row['temperature'] ?? '') ?>"
                        data-bp="<?= htmlspecialchars($row['blood_pressure'] ?? '') ?>"
                        data-pulse="<?= htmlspecialchars($row['pulse'] ?? '') ?>"
                        data-weight="<?= htmlspecialchars($row['weight'] ?? '') ?>"
                        data-notes="<?= htmlspecialchars($row['vital_notes'] ?? '') ?>"
                    >
                        <td><?= htmlspecialchars($row['patient_id']) ?></td>
                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                        <td><?= date("H:i", strtotime($row['assigned_at'])) ?></td>
                        <td class="status"><?= htmlspecialchars($row['status']) ?></td>
                        <td><button class="action-btn start-btn">Start Consultation</button></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($patients)): ?>
                    <tr><td colspan="5" style="text-align:center;color:#999;padding:20px;">No patients waiting.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Lab Test Requests -->
        <div class="section-card">
            <h3 class="section-title">Lab Test Requests</h3>
            <div class="form-grid" style="align-items:end;">
                <div class="form-group">
                    <label>Patient ID</label>
                    <input type="text" id="lab_patient_id" placeholder="Enter Patient ID">
                </div>
                <div class="form-group">
                    <label>Add Test</label>
                    <select id="lab-test-select">
                        <option value="">-- Select Test --</option>
                        <?php foreach ($labTests as $test): ?>
                        <option value="<?= htmlspecialchars($test['test_name']) ?>">
                            <?= htmlspecialchars($test['test_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <input type="text" id="lab_notes" placeholder="Optional notes">
                </div>
                <div class="form-group">
                    <button class="action-btn" id="sendLabRequestBtn" style="width:100%;">
                        <i class="fas fa-paper-plane"></i> Send Request
                    </button>
                </div>
            </div>
            <div id="selected-tests"></div>
        </div>

        <!-- Patient Referral -->
        <div class="referral-card">
            <h3 class="referral-title"><i class="fas fa-share-square" style="margin-right:8px;"></i>Refer Patient to Specialist</h3>

            <div class="referral-grid">
                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> Patient ID</label>
                    <input type="text" id="ref_patient_id" placeholder="Enter Patient ID">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-md"></i> Specialist</label>
                    <select id="ref_specialist">
                        <option value="">-- Select Specialist --</option>
                        <?php foreach ($grouped as $spec => $docs): ?>
                        <optgroup label="<?= htmlspecialchars($spec) ?>">
                            <?php foreach ($docs as $sp): ?>
                            <option
                                value="<?= htmlspecialchars($sp['doctor_id']) ?>"
                                data-name="<?= htmlspecialchars($sp['full_name']) ?>"
                                data-spec="<?= htmlspecialchars($sp['specialization']) ?>"
                                data-email="<?= htmlspecialchars($sp['email']) ?>">
                                Dr. <?= htmlspecialchars($sp['full_name']) ?> — <?= htmlspecialchars($sp['specialization']) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-notes-medical"></i> Reason for Referral</label>
                    <input type="text" id="ref_reason" placeholder="e.g. Suspected cardiac arrhythmia">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment-medical"></i> Additional Notes</label>
                    <input type="text" id="ref_notes" placeholder="Optional — clinical context, urgency, etc.">
                </div>
            </div>

            <div class="specialist-preview" id="specialistPreview">
                <i class="fas fa-user-circle"></i>
                <div class="sp-info">
                    <strong id="sp_name"></strong>
                    <span id="sp_spec" style="color:#6d28d9;"></span><br>
                    <small id="sp_email" style="color:#7c3aed;"></small>
                </div>
            </div>

            <button class="refer-btn" id="sendReferralBtn">
                <i class="fas fa-paper-plane"></i> Send Referral &amp; Notify Specialist
            </button>

            <div class="referral-feedback" id="referralFeedback"></div>
        </div>

        <!-- Consultation Form (hidden until patient selected) -->
        <div class="section-card" id="consultationForm" style="display:none;">
            <h3 class="section-title">Doctor Consultation</h3>

            <div class="vitals-box">
                <h4>Today's Patient Vitals</h4>
                <div class="vitals-grid" id="vitalsContent"></div>
            </div>

            <form method="POST" action="save-prescription.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Consultation ID</label>
                        <input type="text" value="<?= $consultation_id ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Patient ID</label>
                        <input type="text" name="patient_id" id="patientId" readonly>
                    </div>
                    <div class="form-group">
                        <label>Doctor ID</label>
                        <input type="text" name="doctor_id" value="<?= htmlspecialchars($doctor_id) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Consultation Notes</label>
                        <textarea name="consultation_notes" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Diagnosis</label>
                        <textarea name="diagnosis" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Prescription</label>
                        <select name="prescription" id="medicineSelect">
                            <option value="">-- Select Medicine --</option>
                            <?php foreach ($medicines as $med): ?>
                            <option
                                value="<?= htmlspecialchars($med['name']) ?>"
                                data-dosage="<?= htmlspecialchars($med['dosage'] ?? '') ?>">
                                <?= htmlspecialchars($med['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Dosage</label>
                        <input type="text" name="dosage" id="dosageField" placeholder="(mgs)">
                    </div>
                    <div class="form-group">
                        <label>Duration</label>
                        <input type="text" name="duration">
                    </div>
                    <div class="form-group">
                        <label>Frequency</label>
                        <input type="text" name="frequency">
                    </div>
                    <div class="form-group">
                        <label>Instructions</label>
                        <textarea name="instructions" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Doctor Notes</label>
                        <textarea name="doctor_notes" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Consultation Date</label>
                        <input type="datetime-local" name="consultation_date" value="<?= date('Y-m-d\TH:i') ?>" readonly>
                    </div>
                </div>
                <br>
                <button type="submit" class="submit-btn">Complete Diagnosis</button>
            </form>
        </div>

    </main>
</div>

<script>
// ── Vitals helpers ─────────────────────────────────────────────────────
function getVitalClass(type, value) {
    if (value === '' || value === '-') return 'vital';
    const v = parseFloat(value);
    if (type === 'temp')   return (v < 36 || v > 37.5) ? 'vital warning' : 'vital normal';
    if (type === 'pulse')  return (v < 60 || v > 100)  ? 'vital warning' : 'vital normal';
    if (type === 'weight') return (v < 35 || v > 150)  ? 'vital warning' : 'vital normal';
    return 'vital normal';
}

function getBPClass(bp) {
    if (!bp || bp === '-') return 'vital';
    const parts = bp.split('/');
    if (parts.length !== 2) return 'vital';
    const sys = parseInt(parts[0]), dia = parseInt(parts[1]);
    return (sys > 140 || dia > 90 || sys < 90 || dia < 60) ? 'vital warning' : 'vital normal';
}

// ── Start consultation ─────────────────────────────────────────────────
document.querySelectorAll('.start-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const row = this.closest('tr');
        document.getElementById('patientId').value      = row.dataset.id;
        document.getElementById('lab_patient_id').value = row.dataset.id;
        document.getElementById('ref_patient_id').value = row.dataset.id;

        document.getElementById('vitalsContent').innerHTML = `
            <div class="${getVitalClass('temp',   row.dataset.temp)}"><strong>Temperature</strong><br>${row.dataset.temp   || '-'} °C</div>
            <div class="${getBPClass(row.dataset.bp)}"><strong>Blood Pressure</strong><br>${row.dataset.bp     || '-'}</div>
            <div class="${getVitalClass('pulse',  row.dataset.pulse)}"><strong>Pulse Rate</strong><br>${row.dataset.pulse  || '-'} bpm</div>
            <div class="${getVitalClass('weight', row.dataset.weight)}"><strong>Weight</strong><br>${row.dataset.weight || '-'} kg</div>
            <div class="vital"><strong>Notes</strong><br>${row.dataset.notes || '-'}</div>
        `;

        const form = document.getElementById('consultationForm');
        form.style.display = 'block';
        form.scrollIntoView({ behavior: 'smooth' });
    });
});

// ── Auto-fill dosage ───────────────────────────────────────────────────
document.getElementById('medicineSelect').addEventListener('change', function () {
    document.getElementById('dosageField').value =
        this.options[this.selectedIndex].getAttribute('data-dosage') || '';
});

// ── Lab test tags ──────────────────────────────────────────────────────
const selectedTests = new Set();

document.getElementById('lab-test-select').addEventListener('change', function () {
    const val = this.value;
    if (!val || selectedTests.has(val)) { this.value = ''; return; }
    selectedTests.add(val);
    renderTestTags();
    this.value = '';
});

function renderTestTags() {
    const container = document.getElementById('selected-tests');
    container.innerHTML = '';
    selectedTests.forEach(test => {
        const tag = document.createElement('span');
        tag.className = 'test-tag';
        tag.innerHTML = `${test} <button type="button" onclick="removeTest('${test.replace(/'/g,"\\'")}')">&#x2715;</button>`;
        container.appendChild(tag);
    });
}

function removeTest(name) {
    selectedTests.delete(name);
    renderTestTags();
}

document.getElementById('sendLabRequestBtn').addEventListener('click', function () {
    const patientId = document.getElementById('lab_patient_id').value.trim();
    const notes     = document.getElementById('lab_notes').value.trim();

    if (!patientId)              { alert("Please enter a Patient ID."); return; }
    if (selectedTests.size === 0) { alert("Please select at least one lab test."); return; }

    const promises = [...selectedTests].map(test => {
        const data = new URLSearchParams();
        data.append("patient_id",    patientId);
        data.append("test_name",     test);
        data.append("clinical_notes", notes);
        return fetch('save-lab-request.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    data.toString()
        }).then(res => res.json());
    });

    Promise.all(promises).then(results => {
        if (results.every(r => r.success)) {
            alert(`${selectedTests.size} lab request(s) sent successfully.`);
            selectedTests.clear();
            renderTestTags();
            document.getElementById('lab_notes').value = '';
        } else {
            alert("Some requests failed. Please check and try again.");
        }
    });
});

// ── Specialist preview ─────────────────────────────────────────────────
document.getElementById('ref_specialist').addEventListener('change', function () {
    const opt     = this.options[this.selectedIndex];
    const preview = document.getElementById('specialistPreview');

    if (!opt.value) {
        preview.style.display = 'none';
        return;
    }

    document.getElementById('sp_name').textContent  = 'Dr. ' + opt.dataset.name;
    document.getElementById('sp_spec').textContent  = opt.dataset.spec;
    document.getElementById('sp_email').textContent = opt.dataset.email;
    preview.style.display = 'flex';
});

// ── Send referral ──────────────────────────────────────────────────────
document.getElementById('sendReferralBtn').addEventListener('click', function () {
    const patientId    = document.getElementById('ref_patient_id').value.trim();
    const specialistId = document.getElementById('ref_specialist').value.trim();
    const reason       = document.getElementById('ref_reason').value.trim();
    const notes        = document.getElementById('ref_notes').value.trim();
    const btn          = this;

    if (!patientId)    { showFeedback('error', 'Please enter a Patient ID.'); return; }
    if (!specialistId) { showFeedback('error', 'Please select a specialist.'); return; }
    if (!reason)       { showFeedback('error', 'Please provide a reason for the referral.'); return; }

    btn.disabled    = true;
    btn.innerHTML   = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    const data = new URLSearchParams();
    data.append('patient_id',    patientId);
    data.append('specialist_id', specialistId);
    data.append('reason',        reason);
    data.append('notes',         notes);

    fetch('send-referral.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    data.toString()
    })
    .then(res => res.text())
    .then(text => {
        console.log('Referral response:', text);
        let res;
        try {
            res = JSON.parse(text);
        } catch (e) {
            showFeedback('error', '✖ Unexpected server response. Check console for details.');
            return;
        }

        if (res.success) {
            showFeedback('success', `✔ ${res.message} (${res.referral_id})`);
            document.getElementById('ref_patient_id').value        = '';
            document.getElementById('ref_specialist').value        = '';
            document.getElementById('ref_reason').value            = '';
            document.getElementById('ref_notes').value             = '';
            document.getElementById('specialistPreview').style.display = 'none';
        } else {
            showFeedback('error', '✖ ' + res.message);
        }
    })
    .catch(err => {
        console.error('Fetch error:', err);
        showFeedback('error', '✖ Network error. Please try again.');
    })
    .finally(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Referral &amp; Notify Specialist';
    });
});

function showFeedback(type, msg) {
    const fb = document.getElementById('referralFeedback');
    fb.className     = 'referral-feedback ' + type;
    fb.textContent   = msg;
    fb.style.display = 'block';
    setTimeout(() => { fb.style.display = 'none'; }, 6000);
}
</script>

</body>
</html>