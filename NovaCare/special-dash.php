<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Africa/Lusaka');

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header("Location: login.php");
    exit;
}

$specialist_id  = $_SESSION['user_id'];
$specialistName = "Specialist";

$stmt = $conn->prepare("SELECT full_name, specialization FROM doctors WHERE doctor_id = ?");
$stmt->bind_param("s", $specialist_id);
$stmt->execute();
$stmt->bind_result($specialistName, $specialization);
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

// Referred patients
$referrals = [];
$stmt = $conn->prepare("
    SELECT
        pr.referral_id,
        pr.patient_id,
        pr.reason,
        pr.notes      AS referral_notes,
        pr.status     AS referral_status,
        pr.referred_at,
        p.full_name   AS patient_name,
        p.phone,
        d.full_name   AS referring_doctor
    FROM preferrals pr
    INNER JOIN patients p  ON pr.patient_id          = p.patient_id
    INNER JOIN doctors  d  ON pr.referring_doctor_id = d.doctor_id
    WHERE pr.specialist_id = ?
    ORDER BY pr.referred_at DESC
");
$stmt->bind_param("s", $specialist_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $referrals[] = $row;
$stmt->close();

// Medicines
$medicines = [];
$result = $conn->query("SELECT medicine_id, name, dosage FROM medicines ORDER BY name ASC");
while ($row = $result->fetch_assoc()) $medicines[] = $row;

// Lab test types
$labTests = [];
$result = $conn->query("SELECT test_name FROM lab_test_types ORDER BY test_name ASC");
while ($row = $result->fetch_assoc()) $labTests[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse | Specialist Dashboard</title>
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

.page-header { margin-bottom:25px; }
.page-header h2 { font-size:24px; color:#0f4c81; margin-bottom:4px; }
.page-header p  { color:#777; font-size:14px; }

.section-card { background:#fff; padding:25px; border-radius:12px; margin-bottom:25px; box-shadow:0 8px 20px rgba(0,0,0,.05); }
.section-title { display:flex; align-items:center; gap:10px; margin-bottom:18px; color:#0f4c81; font-size:17px; padding-bottom:12px; border-bottom:1px solid #eef0f5; }

table { width:100%; border-collapse:collapse; }
th, td { padding:12px; border-bottom:1px solid #eee; font-size:13px; text-align:left; vertical-align:middle; }
th { background:#0f4c81; color:#fff; font-size:12px; white-space:nowrap; }
tr:hover td { background:#f9fbff; }
.empty { text-align:center; color:#999; padding:25px; }

label { display:block; margin-bottom:6px; font-size:13px; font-weight:600; color:#444; }
input, select, textarea { width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; font-size:14px; color:#333; transition:border-color .2s; }
input:focus, select:focus, textarea:focus { outline:none; border-color:#1fa2ff; }
input[readonly], input:disabled, select:disabled, textarea:disabled { background:#f5f7fa; cursor:default; color:#999; }
textarea { resize:none; }

.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:15px; }
.form-group { display:flex; flex-direction:column; }

.btn { background:#1fa2ff; color:#fff; border:none; padding:10px 16px; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px; transition:background .2s; }
.btn:hover { background:#1685d9; }
.btn-sm { padding:6px 12px; font-size:12px; border-radius:5px; border:none; cursor:pointer; font-weight:600; transition:.2s; display:inline-block; }
.btn-view    { background:#eef5ff; color:#0f4c81; }
.btn-view:hover { background:#dbeafe; }
.btn-consult { background:#dcfce7; color:#166534; }
.btn-consult:hover { background:#bbf7d0; }
.btn-locked  { background:#f1f5f9; color:#94a3b8; cursor:not-allowed; }
.btn-submit  { background:#0f4c81; color:#fff; padding:12px 24px; border-radius:8px; border:none; cursor:pointer; font-weight:600; font-size:14px; margin-top:10px; transition:.2s; }
.btn-submit:hover:not(:disabled) { background:#0c3d67; }
.btn-submit:disabled { background:#94a3b8; cursor:not-allowed; opacity:.7; }

.badge { display:inline-block; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.badge-pending   { background:#fff3cd; color:#856404; }
.badge-accepted  { background:#dcfce7; color:#166534; }
.badge-completed { background:#dbeafe; color:#1d4ed8; }
.badge-rejected  { background:#fee2e2; color:#991b1b; }

.vitals-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-top:12px; }
.vital-box { padding:14px; border-radius:8px; text-align:center; font-size:13px; background:#f0f4fa; }
.vital-box strong { display:block; margin-bottom:4px; font-size:12px; color:#555; }
.vital-box span { font-size:18px; font-weight:700; color:#0f4c81; }
.vital-box.warning { background:#fee2e2; }
.vital-box.warning span { color:#991b1b; }
.vital-box.normal  { background:#dcfce7; }
.vital-box.normal  span { color:#166534; }

#sp-selected-tests { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; min-height:36px; }
.test-tag { display:inline-flex; align-items:center; gap:6px; background:#eaf3ff; border:1px solid #b3d4f5; color:#0f4c81; border-radius:20px; padding:5px 12px; font-size:13px; }
.test-tag button { background:none; border:none; color:#c0392b; cursor:pointer; font-size:15px; padding:0; line-height:1; width:auto; }

.patient-panel { background:#f4f8ff; border:1px solid #ccdeff; border-radius:10px; padding:18px 20px; margin-bottom:20px; display:none; }
.patient-panel h4 { color:#0f4c81; margin-bottom:4px; font-size:15px; }
.patient-panel p  { color:#555; font-size:13px; }

.alert { padding:12px 16px; border-radius:8px; font-size:13px; font-weight:600; margin-bottom:18px; display:none; }
.alert-success { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.alert-error   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

.tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
.tab-btn { padding:8px 18px; border-radius:6px; border:2px solid transparent; background:#eef2fb; color:#0f4c81; font-size:13px; font-weight:600; cursor:pointer; transition:.2s; }
.tab-btn.active { background:#0f4c81; color:#fff; }
.tab-btn:hover:not(.active) { background:#dbeafe; }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

.lock-banner { background:#dcfce7; color:#166534; border:1px solid #86efac; padding:12px 16px; border-radius:8px; font-size:13px; font-weight:600; margin-bottom:18px; display:none; }
.lock-banner i { margin-right:6px; }
</style>
</head>
<body>

<header>
    <div><h1>MediPulse</h1></div>
    <div>Logged in as <strong><?= htmlspecialchars($specialistName) ?></strong></div>
</header>

<div class="container">
    <nav class="sidebar">
        <ul>
            <li><a href="doctor-dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="active"><a href="special-dash.php"><i class="fas fa-user-injured"></i> My Patients</a></li>
        </ul>
    </nav>

    <main class="main-content">

        <!-- Flash messages -->
        <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success" style="display:block;">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-error" style="display:block;">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); endif; ?>

        <div class="page-header">
            <h2>Specialist Dashboard</h2>
            <p>View referred patients, check vitals, request lab tests and perform consultations</p>
        </div>

        <!-- ── Referred Patients Table ─────────────────────────────── -->
        <div class="section-card">
            <h3 class="section-title"><i class="fas fa-share-square"></i> Referred Patients</h3>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Referral ID</th>
                            <th>Patient</th>
                            <th>Referred By</th>
                            <th>Reason</th>
                            <th>Notes</th>
                            <th>Referred At</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($referrals) > 0): ?>
                        <?php foreach ($referrals as $ref):
                            $status    = strtolower($ref['referral_status']);
                            $badgeClass = match($status) {
                                'pending'   => 'badge-pending',
                                'accepted'  => 'badge-accepted',
                                'completed' => 'badge-completed',
                                'rejected'  => 'badge-rejected',
                                default     => 'badge-pending'
                            };
                            $pid  = htmlspecialchars($ref['patient_id']);
                            $pnam = htmlspecialchars($ref['patient_name'], ENT_QUOTES);
                            $rid  = htmlspecialchars($ref['referral_id']);
                            $rsn  = htmlspecialchars($ref['reason'], ENT_QUOTES);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($ref['referral_id']) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($ref['patient_name']) ?></strong><br>
                                <small style="color:#888;"><?= $pid ?></small>
                            </td>
                            <td>Dr. <?= htmlspecialchars($ref['referring_doctor']) ?></td>
                            <td><?= htmlspecialchars($ref['reason']) ?></td>
                            <td><?= htmlspecialchars($ref['referral_notes'] ?: '—') ?></td>
                            <td><?= date("d M Y H:i", strtotime($ref['referred_at'])) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($ref['referral_status']) ?></span></td>
                            <td style="white-space:nowrap;">
                                <!-- View always available -->
                                <button class="btn-sm btn-view"
                                    onclick="loadPatient('<?= $pid ?>','<?= $pnam ?>','<?= $rid ?>','<?= $rsn ?>','<?= $status ?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>

                                <!-- Consult only if not completed -->
                                <?php if ($status !== 'completed'): ?>
                                <button class="btn-sm btn-consult" style="margin-top:4px;"
                                    onclick="loadPatient('<?= $pid ?>','<?= $pnam ?>','<?= $rid ?>','<?= $rsn ?>','<?= $status ?>'); scrollToConsult();">
                                    <i class="fas fa-stethoscope"></i> Consult
                                </button>
                                <?php else: ?>
                                <span class="btn-sm btn-locked" style="margin-top:4px;">
                                    <i class="fas fa-lock"></i> Completed
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="empty">No referrals found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Active Patient Panel ────────────────────────────────── -->
        <div class="patient-panel" id="patientPanel">
            <h4 id="panelPatientName"></h4>
            <p id="panelPatientMeta"></p>
        </div>

        <!-- ── Tabs ───────────────────────────────────────────────── -->
        <div id="patientTabs" style="display:none;">
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('vitals',this)"><i class="fas fa-heartbeat"></i> Vitals</button>
                <button class="tab-btn" onclick="switchTab('labresults',this)"><i class="fas fa-flask"></i> Lab Results</button>
                <button class="tab-btn" onclick="switchTab('labrequest',this)"><i class="fas fa-paper-plane"></i> Request Lab Test</button>
                <button class="tab-btn" onclick="switchTab('consultation',this)"><i class="fas fa-stethoscope"></i> Consultation</button>
            </div>

            <!-- Vitals Tab -->
            <div class="tab-pane active section-card" id="tab-vitals">
                <h3 class="section-title"><i class="fas fa-heartbeat"></i> Latest Patient Vitals</h3>
                <div id="vitalsContent">
                    <p style="color:#999;font-size:13px;">Select a patient to view vitals.</p>
                </div>
            </div>

            <!-- Lab Results Tab -->
            <div class="tab-pane section-card" id="tab-labresults">
                <h3 class="section-title"><i class="fas fa-flask"></i> Lab Results</h3>
                <div id="labResultsContent">
                    <p style="color:#999;font-size:13px;">Select a patient to view lab results.</p>
                </div>
            </div>

            <!-- Lab Request Tab -->
            <div class="tab-pane section-card" id="tab-labrequest">
                <h3 class="section-title"><i class="fas fa-paper-plane"></i> Request Lab Test</h3>
                <div class="alert" id="labAlert"></div>
                <div class="form-grid" style="align-items:end;">
                    <div class="form-group">
                        <label>Patient ID</label>
                        <input type="text" id="lab_patient_id" readonly>
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
                        <label>Clinical Notes</label>
                        <input type="text" id="lab_notes" placeholder="Optional notes">
                    </div>
                    <div class="form-group">
                        <button class="btn" id="sendLabRequestBtn" style="width:100%;">
                            <i class="fas fa-paper-plane"></i> Send Request
                        </button>
                    </div>
                </div>
                <div id="sp-selected-tests"></div>
            </div>

            <!-- Consultation Tab -->
            <div class="tab-pane section-card" id="tab-consultation">
                <h3 class="section-title"><i class="fas fa-stethoscope"></i> Specialist Consultation</h3>

                <!-- Lock banner shown when referral is already completed -->
                <div class="lock-banner" id="completedBanner">
                    <i class="fas fa-lock"></i> This referral is complete. No further consultations can be recorded.
                </div>

                <div class="alert" id="consultAlert"></div>

                <form method="POST" action="save-prescription.php" id="consultForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Consultation ID</label>
                            <input type="text" value="<?= $consultation_id ?>" name="consultation_id" readonly>
                        </div>
                        <div class="form-group">
                            <label>Patient ID</label>
                            <input type="text" name="patient_id" id="consult_patient_id" readonly>
                        </div>
                        <div class="form-group">
                            <label>Doctor ID</label>
                            <input type="text" name="doctor_id" value="<?= htmlspecialchars($specialist_id) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Referral Reason</label>
                            <input type="text" id="consult_reason" name="referral_reason" readonly>
                        </div>
                        <div class="form-group">
                            <label>Consultation Notes <span style="color:red;">*</span></label>
                            <textarea name="consultation_notes" rows="3" required placeholder="Clinical findings, observations..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Diagnosis <span style="color:red;">*</span></label>
                            <textarea name="diagnosis" rows="3" required placeholder="Primary diagnosis..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Prescription</label>
                            <select name="prescription" id="medicineSelect">
                                <option value="">-- Select Medicine --</option>
                                <?php foreach ($medicines as $med): ?>
                                <option value="<?= htmlspecialchars($med['name']) ?>"
                                    data-dosage="<?= htmlspecialchars($med['dosage'] ?? '') ?>">
                                    <?= htmlspecialchars($med['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Dosage</label>
                            <input type="text" name="dosage" id="dosageField" placeholder="e.g. 500mg">
                        </div>
                        <div class="form-group">
                            <label>Duration</label>
                            <input type="text" name="duration" placeholder="e.g. 7 days">
                        </div>
                        <div class="form-group">
                            <label>Frequency</label>
                            <input type="text" name="frequency" placeholder="e.g. Twice daily">
                        </div>
                        <div class="form-group">
                            <label>Instructions</label>
                            <textarea name="instructions" rows="2" placeholder="e.g. Take after meals"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Doctor Notes</label>
                            <textarea name="doctor_notes" rows="2" placeholder="Additional notes for records..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Consultation Date</label>
                            <input type="datetime-local" name="consultation_date" value="<?= date('Y-m-d\TH:i') ?>" readonly>
                        </div>
                    </div>
                    <button type="submit" class="btn-submit" id="consultSubmitBtn">
                        <i class="fas fa-check-circle"></i> Complete Consultation
                    </button>
                </form>
            </div>

        </div><!-- end patientTabs -->

    </main>
</div>

<script>
let activePatientId   = '';
let activePatientName = '';
let activeReferralId  = '';

// ── Load patient ───────────────────────────────────────────────────────
function loadPatient(patientId, patientName, referralId, reason, status) {
    activePatientId   = patientId;
    activePatientName = patientName;
    activeReferralId  = referralId;

    document.getElementById('panelPatientName').textContent = patientName + ' (' + patientId + ')';
    document.getElementById('panelPatientMeta').textContent = 'Referral: ' + referralId + '  |  Reason: ' + reason;
    document.getElementById('patientPanel').style.display   = 'block';
    document.getElementById('patientTabs').style.display    = 'block';

    document.getElementById('lab_patient_id').value     = patientId;
    document.getElementById('consult_patient_id').value = patientId;
    document.getElementById('consult_reason').value     = reason;

    fetchVitals(patientId);
    fetchLabResults(patientId);

    // Lock consultation if referral is completed
    const isCompleted = (status === 'completed');
    const form        = document.getElementById('consultForm');
    const submitBtn   = document.getElementById('consultSubmitBtn');
    const banner      = document.getElementById('completedBanner');

    // Toggle all form fields
    form.querySelectorAll('input:not([name="consultation_id"]):not([name="doctor_id"]):not([name="consultation_date"]), select, textarea').forEach(el => {
        el.disabled = isCompleted;
    });

    if (isCompleted) {
        submitBtn.disabled  = true;
        submitBtn.innerHTML = '<i class="fas fa-lock"></i> Consultation Already Completed';
        banner.style.display = 'block';
    } else {
        submitBtn.disabled  = false;
        submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Complete Consultation';
        banner.style.display = 'none';
    }

    document.getElementById('patientPanel').scrollIntoView({ behavior: 'smooth' });
}

function scrollToConsult() {
    setTimeout(() => {
        const btn = document.querySelector('.tab-btn:nth-child(4)');
        switchTab('consultation', btn);
        document.getElementById('tab-consultation').scrollIntoView({ behavior: 'smooth' });
    }, 100);
}

// ── Tabs ───────────────────────────────────────────────────────────────
function switchTab(name, el) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    if (el) el.classList.add('active');
}

// ── Fetch Vitals ───────────────────────────────────────────────────────
function fetchVitals(patientId) {
    const box = document.getElementById('vitalsContent');
    box.innerHTML = '<p style="color:#999;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading vitals...</p>';

    fetch('get-vitals.php?patient_id=' + encodeURIComponent(patientId))
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(raw => {
        console.log('Vitals raw:', raw);
        const data = JSON.parse(raw);
        if (!data || data.error) {
            box.innerHTML = '<p style="color:#999;font-size:13px;">' + (data.error || 'No vitals recorded yet.') + '</p>';
            return;
        }
        box.innerHTML = `
            <p style="font-size:12px;color:#888;margin-bottom:10px;">Recorded: ${data.recorded_at || '—'}</p>
            <div class="vitals-grid">
                <div class="vital-box ${vitalClass('temp', data.temperature)}">
                    <strong>Temperature</strong>
                    <span>${data.temperature || '—'}</span>
                    <small>°C</small>
                </div>
                <div class="vital-box ${bpClass(data.blood_pressure)}">
                    <strong>Blood Pressure</strong>
                    <span>${data.blood_pressure || '—'}</span>
                    <small>mmHg</small>
                </div>
                <div class="vital-box ${vitalClass('pulse', data.pulse)}">
                    <strong>Pulse Rate</strong>
                    <span>${data.pulse || '—'}</span>
                    <small>bpm</small>
                </div>
                <div class="vital-box">
                    <strong>Respiratory Rate</strong>
                    <span>${data.respiratory_rate || '—'}</span>
                    <small>breaths/min</small>
                </div>
                <div class="vital-box ${vitalClass('weight', data.weight)}">
                    <strong>Weight</strong>
                    <span>${data.weight || '—'}</span>
                    <small>kg</small>
                </div>
                <div class="vital-box">
                    <strong>Notes</strong>
                    <span style="font-size:13px;">${data.notes || '—'}</span>
                </div>
            </div>
        `;
    })
    .catch(err => {
        console.error('Vitals error:', err);
        box.innerHTML = '<p style="color:#c0392b;font-size:13px;">Failed to load vitals: ' + err.message + '</p>';
    });
}

function vitalClass(type, value) {
    if (!value) return '';
    const v = parseFloat(value);
    if (type === 'temp')   return (v < 36 || v > 37.5) ? 'warning' : 'normal';
    if (type === 'pulse')  return (v < 60 || v > 100)  ? 'warning' : 'normal';
    if (type === 'weight') return (v < 35 || v > 150)  ? 'warning' : 'normal';
    return '';
}

function bpClass(bp) {
    if (!bp) return '';
    const parts = bp.split('/');
    if (parts.length !== 2) return '';
    const sys = parseInt(parts[0]), dia = parseInt(parts[1]);
    return (sys > 140 || dia > 90 || sys < 90 || dia < 60) ? 'warning' : 'normal';
}

// ── Fetch Lab Results ──────────────────────────────────────────────────
function fetchLabResults(patientId) {
    const box = document.getElementById('labResultsContent');
    box.innerHTML = '<p style="color:#999;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading lab results...</p>';

    fetch('get-lab-results.php?patient_id=' + encodeURIComponent(patientId))
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(raw => {
        console.log('Lab results raw:', raw);
        const data = JSON.parse(raw);
        if (!Array.isArray(data)) {
            box.innerHTML = '<p style="color:#c0392b;font-size:13px;">Error: ' + (data.error || 'Unexpected response') + '</p>';
            return;
        }
        if (data.length === 0) {
            box.innerHTML = '<p style="color:#999;font-size:13px;">No lab results found for this patient.</p>';
            return;
        }
        let html = `<div style="overflow-x:auto;"><table>
            <thead><tr>
                <th>Result ID</th><th>Test Name</th><th>Result</th>
                <th>Result Date</th><th>Recorded By</th>
            </tr></thead><tbody>`;
        data.forEach(r => {
            html += `<tr>
                <td>${r.lab_result_id}</td>
                <td>${r.test_name}</td>
                <td><strong>${r.result}</strong></td>
                <td>${r.result_date}</td>
                <td>${r.lab_staff}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        box.innerHTML = html;
    })
    .catch(err => {
        console.error('Lab results error:', err);
        box.innerHTML = '<p style="color:#c0392b;font-size:13px;">Failed to load lab results: ' + err.message + '</p>';
    });
}

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
    const container = document.getElementById('sp-selected-tests');
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
    const alertEl   = document.getElementById('labAlert');

    if (!patientId)               { showAlert(alertEl, 'error', 'No patient selected.'); return; }
    if (selectedTests.size === 0) { showAlert(alertEl, 'error', 'Please select at least one test.'); return; }

    const promises = [...selectedTests].map(test => {
        const data = new URLSearchParams();
        data.append('patient_id',     patientId);
        data.append('test_name',      test);
        data.append('clinical_notes', notes);
        return fetch('save-lab-request.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    data.toString()
        }).then(r => r.json());
    });

    Promise.all(promises).then(results => {
        if (results.every(r => r.success)) {
            showAlert(alertEl, 'success', selectedTests.size + ' lab request(s) sent successfully.');
            selectedTests.clear();
            renderTestTags();
            document.getElementById('lab_notes').value = '';
        } else {
            showAlert(alertEl, 'error', 'Some requests failed. Please try again.');
        }
    });
});

// ── Medicine auto-fill dosage ──────────────────────────────────────────
document.getElementById('medicineSelect').addEventListener('change', function () {
    document.getElementById('dosageField').value =
        this.options[this.selectedIndex].getAttribute('data-dosage') || '';
});

// ── Alert helper ───────────────────────────────────────────────────────
function showAlert(el, type, msg) {
    el.className     = 'alert alert-' + type;
    el.textContent   = msg;
    el.style.display = 'block';
    setTimeout(() => { el.style.display = 'none'; }, 5000);
}
</script>

</body>
</html>