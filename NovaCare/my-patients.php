<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header("Location: login.php");
    exit;
}

$doctor_id = $_SESSION['user_id'];

function e($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$doctorName = "Doctor";
$stmt = $conn->prepare("SELECT full_name FROM doctors WHERE doctor_id = ?");
$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$stmt->bind_result($doctorName);
$stmt->fetch();
$stmt->close();

/* ── Distinct patients this doctor has consulted ── */
$stmt = $conn->prepare("
    SELECT DISTINCT p.patient_id, p.full_name, p.phone, p.nrc
    FROM consultations c
    INNER JOIN patients p ON p.patient_id = c.patient_id
    WHERE c.doctor_id = ?
    ORDER BY p.full_name ASC
");
$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$pResult = $stmt->get_result();
$patients = [];
while ($row = $pResult->fetch_assoc()) {
    $patients[] = $row;
}
$stmt->close();

/* ── Consultations per patient (for this doctor) ── */
$stmt = $conn->prepare("
    SELECT
        c.consultation_id,
        c.patient_id,
        c.diagnosis,
        c.consultation_notes,
        c.doctor_notes,
        c.consultation_date
    FROM consultations c
    WHERE c.doctor_id = ?
    ORDER BY c.consultation_date DESC
");
$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$cResult = $stmt->get_result();
$consultations = [];
while ($row = $cResult->fetch_assoc()) {
    $consultations[$row['patient_id']][] = $row;
}
$stmt->close();

/* ── Prescriptions per patient (no consultation_id in table) ── */
$stmt = $conn->prepare("
    SELECT
        pr.prescription_id,
        pr.patient_id,
        pr.diagnosis,
        pr.consultation_notes,
        pr.dosage,
        pr.frequency,
        pr.duration,
        pr.instructions,
        pr.status,
        pr.quantity_dispensed,
        pr.created_at,
        pr.dispensed_at,
        m.name AS medicine_name
    FROM prescriptions pr
    LEFT JOIN medicines m ON m.medicine_id = pr.medicine_id
    WHERE pr.doctor_id = ?
    ORDER BY pr.created_at DESC
");
$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$prResult = $stmt->get_result();
$prescriptions = [];
while ($row = $prResult->fetch_assoc()) {
    $prescriptions[$row['patient_id']][] = $row;
}
$stmt->close();

/* ── Lab requests + results ── */
$stmt = $conn->prepare("
    SELECT
        lr.lab_request_id,
        lr.consultation_id,
        lr.patient_id,
        lr.test_name,
        lr.clinical_notes,
        lr.requested_at,
        lres.result,
        lres.result_date
    FROM lab_requests lr
    LEFT JOIN lab_results lres ON lres.lab_request_id = lr.lab_request_id
    WHERE lr.doctor_id = ?
    ORDER BY lr.requested_at ASC
");
$stmt->bind_param("s", $doctor_id);
$stmt->execute();
$lrResult = $stmt->get_result();
// Key by consultation_id if set, otherwise by patient_id as fallback
$labRequests  = []; // keyed by consultation_id
$labByPatient = []; // keyed by patient_id (only rows with no consultation_id)
while ($row = $lrResult->fetch_assoc()) {
    if (!empty($row['consultation_id'])) {
        $labRequests[$row['consultation_id']][] = $row;
    } else {
        $labByPatient[$row['patient_id']][] = $row;
    }
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Patients</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }
header { background:#0f4c81; color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; }
.container { display:flex; min-height:calc(100vh - 70px); }
.sidebar { width:240px; background:#fff; box-shadow:2px 0 10px rgba(0,0,0,.05); padding-top:20px; }
.sidebar ul { list-style:none; }
.sidebar ul li { padding:15px 25px; border-left:4px solid transparent; transition:.3s; }
.sidebar ul li:hover, .sidebar ul li.active { background:#f0f6ff; border-left:4px solid #0f4c81; }
.sidebar ul li a { text-decoration:none; color:#333; display:block; }
.main-content { flex:1; padding:30px; }
.card { background:#fff; padding:25px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,.05); margin-bottom:25px; }
.section-title { color:#0f4c81; margin-bottom:18px; }

/* Search */
.search-bar { display:flex; gap:10px; margin-bottom:20px; }
.search-bar input { flex:1; padding:10px 14px; border:1px solid #ccc; border-radius:8px; font-size:14px; }

/* Patient list table */
table.patient-list { width:100%; border-collapse:collapse; }
table.patient-list th, table.patient-list td { padding:12px 14px; border-bottom:1px solid #eee; font-size:13px; text-align:left; }
table.patient-list th { background:#0f4c81; color:#fff; }
table.patient-list tbody tr.patient-row { cursor:pointer; transition:background .15s; }
table.patient-list tbody tr.patient-row:hover { background:#f0f6ff; }
table.patient-list tbody tr.patient-row td .toggle-icon { float:right; color:#0f4c81; transition:transform .2s; }
table.patient-list tbody tr.patient-row.open td .toggle-icon { transform:rotate(180deg); }

/* Detail panel */
tr.detail-row td { padding:0; background:#f9fbff; }
.detail-panel { padding:20px 24px; display:none; }
.detail-panel.visible { display:block; }

/* Consultation block */
.consult-block { border:1px solid #dde6f0; border-radius:10px; padding:16px; margin-bottom:16px; background:#fff; }
.consult-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.consult-id { font-weight:700; color:#0f4c81; font-size:13px; }
.consult-date { font-size:12px; color:#888; }
.info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-bottom:14px; }
.info-item label { display:block; font-size:11px; font-weight:700; color:#888; text-transform:uppercase; margin-bottom:3px; }
.info-item span { font-size:13px; color:#333; }

/* Sub-tables inside detail */
.sub-section { margin-top:14px; }
.sub-section h5 { font-size:12px; font-weight:700; text-transform:uppercase; color:#0f4c81; margin-bottom:8px; letter-spacing:.5px; }
table.sub-table { width:100%; border-collapse:collapse; font-size:13px; }
table.sub-table th { background:#e8f1fb; color:#0f4c81; padding:8px 10px; text-align:left; }
table.sub-table td { padding:8px 10px; border-bottom:1px solid #eef2f7; }
table.sub-table tr:last-child td { border-bottom:none; }

/* Status badges */
.badge { display:inline-block; padding:3px 9px; border-radius:12px; font-size:11px; font-weight:700; }
.badge-pending  { background:#fff3cd; color:#856404; }
.badge-done     { background:#d1fae5; color:#065f46; }
.badge-none     { background:#f1f1f1; color:#999; }

.no-data { color:#aaa; font-style:italic; font-size:13px; }
</style>
</head>
<body>

<header>
    <h2>MediPulse</h2>
    <div>Logged in as <strong><?= e($doctorName) ?></strong></div>
</header>

<div class="container">

<nav class="sidebar">
    <ul>
        <li><a href="doctor-dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
        <li class="active"><a href="my-patients.php"><i class="fas fa-user-injured"></i> My Patients</a></li>
    </ul>
</nav>

<main class="main-content">
<div class="card">
    <h3 class="section-title">My Patients & Medical History</h3>

    <div class="search-bar">
        <input type="text" id="searchInput" placeholder="Search by name, NRC or patient ID…" oninput="filterPatients()">
    </div>

    <?php if (empty($patients)): ?>
        <p class="no-data">No patients found for your account.</p>
    <?php else: ?>

    <table class="patient-list" id="patientTable">
        <thead>
            <tr>
                <th>Patient ID</th>
                <th>Full Name</th>
                <th>NRC</th>
                <th>Phone</th>
                <th>Consultations</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($patients as $p):
            $pid   = $p['patient_id'];
            $cons  = $consultations[$pid] ?? [];
            $count = count($cons);
        ?>
            <!-- Summary row -->
            <tr class="patient-row" data-patient="<?= e($pid) ?>" onclick="toggleDetail('<?= e($pid) ?>', this)">
                <td><?= e($pid) ?></td>
                <td><?= e($p['full_name']) ?></td>
                <td><?= e($p['nrc']) ?></td>
                <td><?= e($p['phone']) ?></td>
                <td><?= $count ?> visit<?= $count !== 1 ? 's' : '' ?></td>
                <td><i class="fas fa-chevron-down toggle-icon"></i></td>
            </tr>

            <!-- Detail row -->
            <tr class="detail-row" id="detail-<?= e($pid) ?>">
                <td colspan="6">
                    <div class="detail-panel" id="panel-<?= e($pid) ?>">

                        <?php if (empty($cons)): ?>
                            <p class="no-data">No consultation records.</p>
                        <?php else: ?>

                        <?php foreach ($cons as $c):
                            $cid  = $c['consultation_id'];
                            // Use consultation_id match; patient fallback only on last
                            // consultation so orphaned requests appear once, not per-visit
                            $labs = $labRequests[$cid] ?? [];
                            if (empty($labs) && $c === end($cons)) {
                                $labs = $labByPatient[$pid] ?? [];
                            }
                        ?>
                        <div class="consult-block">
                            <div class="consult-header">
                                <span class="consult-id"><i class="fas fa-stethoscope"></i> <?= e($cid) ?></span>
                                <span class="consult-date"><?= e($c['consultation_date']) ?></span>
                            </div>

                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Diagnosis</label>
                                    <span><?= e($c['diagnosis']) ?: '<em class="no-data">—</em>' ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Consultation Notes</label>
                                    <span><?= e($c['consultation_notes']) ?: '<em class="no-data">—</em>' ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Doctor Notes</label>
                                    <span><?= e($c['doctor_notes']) ?: '<em class="no-data">—</em>' ?></span>
                                </div>
                            </div>

                            <!-- Lab Tests -->
                            <div class="sub-section">
                                <h5><i class="fas fa-flask"></i> Lab Tests & Results</h5>
                                <?php if (empty($labs)): ?>
                                    <p class="no-data">No lab tests requested for this consultation.</p>
                                <?php else: ?>
                                <table class="sub-table">
                                    <thead>
                                        <tr>
                                            <th>Test</th>
                                            <th>Result</th>
                                            <th>Result Date</th>
                                            <th>Requested</th>
                                            <th>Clinical Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($labs as $lr):
                                        ?>
                                        <tr>
                                            <td><?= e($lr['test_name']) ?></td>
                                            <td><?= e($lr['result']) ?: '—' ?></td>
                                            <td><?= e($lr['result_date']) ?: '—' ?></td>
                                            <td><?= e($lr['requested_at']) ?: '—' ?></td>
                                            <td><?= e($lr['clinical_notes']) ?: '—' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>

                            <!-- Lab Tests -->
                            <div class="sub-section">
                                <h5><i class="fas fa-flask"></i> Lab Tests & Results</h5>
                                <?php if (empty($labs)): ?>
                                    <p class="no-data">No lab tests requested for this consultation.</p>
                                <?php else: ?>
                                <table class="sub-table">
                                    <thead>
                                        <tr>
                                            <th>Test</th>
                                            <th>Result</th>
                                            <th>Result Date</th>
                                            <th>Requested</th>
                                            <th>Clinical Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($labs as $lr):
                                        ?>
                                        <tr>
                                            <td><?= e($lr['test_name']) ?></td>
                                            <td><?= e($lr['result']) ?: '—' ?></td>
                                            <td><?= e($lr['result_date']) ?: '—' ?></td>
                                            <td><?= e($lr['requested_at']) ?: '—' ?></td>
                                            <td><?= e($lr['clinical_notes']) ?: '—' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>

                        </div><!-- /.consult-block -->
                        <?php endforeach; ?>

                        <!-- Prescriptions (keyed by patient, shown once per patient) -->
                        <?php $pres = $prescriptions[$pid] ?? []; ?>
                        <div class="consult-block" style="border-color:#d4edda;">
                            <div class="consult-header">
                                <span class="consult-id" style="color:#166534;"><i class="fas fa-pills"></i> Prescriptions</span>
                            </div>
                            <?php if (empty($pres)): ?>
                                <p class="no-data">No prescriptions recorded for this patient.</p>
                            <?php else: ?>
                            <table class="sub-table">
                                <thead>
                                    <tr>
                                        <th>Medicine</th>
                                        <th>Diagnosis</th>
                                        <th>Dosage</th>
                                        <th>Frequency</th>
                                        <th>Duration</th>
                                        <th>Instructions</th>
                                        <th>Status</th>
                                        <th>Qty Dispensed</th>
                                        <th>Prescribed</th>
                                        <th>Dispensed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pres as $pr):
                                        $pstatus = strtolower($pr['status'] ?? '');
                                        $pBadge  = match($pstatus) {
                                            'dispensed'  => 'badge-done',
                                            'pending'    => 'badge-pending',
                                            default      => 'badge-none'
                                        };
                                    ?>
                                    <tr>
                                        <td><?= e($pr['medicine_name']) ?></td>
                                        <td><?= e($pr['diagnosis']) ?: '—' ?></td>
                                        <td><?= e($pr['dosage']) ?></td>
                                        <td><?= e($pr['frequency']) ?></td>
                                        <td><?= e($pr['duration']) ?></td>
                                        <td><?= e($pr['instructions']) ?: '—' ?></td>
                                        <td><span class="badge <?= $pBadge ?>"><?= e($pr['status']) ?: 'Unknown' ?></span></td>
                                        <td><?= e($pr['quantity_dispensed']) ?: '—' ?></td>
                                        <td><?= e($pr['created_at']) ?></td>
                                        <td><?= e($pr['dispensed_at']) ?: '—' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>

                        <?php endif; ?>

                    </div><!-- /.detail-panel -->
                </td>
            </tr>

        <?php endforeach; ?>
        </tbody>
    </table>

    <?php endif; ?>
</div>
</main>
</div>

<script>
function toggleDetail(pid, row) {
    const panel = document.getElementById('panel-' + pid);
    const isOpen = panel.classList.contains('visible');
    // Close all
    document.querySelectorAll('.detail-panel').forEach(p => p.classList.remove('visible'));
    document.querySelectorAll('.patient-row').forEach(r => r.classList.remove('open'));
    // Open clicked if it was closed
    if (!isOpen) {
        panel.classList.add('visible');
        row.classList.add('open');
    }
}

function filterPatients() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.patient-row').forEach(row => {
        const pid  = row.dataset.patient.toLowerCase();
        const text = row.innerText.toLowerCase();
        const show = !q || text.includes(q) || pid.includes(q);
        row.style.display = show ? '' : 'none';
        // Also hide the detail row when patient is hidden
        const detail = document.getElementById('detail-' + row.dataset.patient);
        if (detail) detail.style.display = show ? '' : 'none';
    });
}
</script>

</body>
</html>