<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header("Location: patient-login.php");
    exit;
}

session_regenerate_id(true);

$patient_id  = $_SESSION['user_id'];
$patientName = $_SESSION['full_name'];
$success = "";
$error   = "";

// Fetch all doctor schedules
$allSchedules = [];
$result = $conn->query("
    SELECT dws.doctor_id, dws.schedule_date, dws.shift_type, d.full_name, d.type, d.specialization
    FROM doctor_weekly_schedules dws
    INNER JOIN doctors d ON dws.doctor_id = d.doctor_id
    WHERE dws.schedule_date >= CURDATE()
    ORDER BY dws.doctor_id ASC, dws.schedule_date ASC
");
while ($row = $result->fetch_assoc()) {
    $id = trim($row['doctor_id']);
    $allSchedules[$id][] = [
        'date'           => $row['schedule_date'],
        'shift'          => $row['shift_type'],
        'type'           => $row['type'],
        'specialization' => $row['specialization']
    ];
}

if (isset($_POST['request_appointment'])) {
    $doctor_id = trim($_POST['doctor_id']);
    $date      = trim($_POST['preferred_date']);
    $time      = trim($_POST['preferred_time']);
    $reason    = trim($_POST['reason']);
    $shift     = trim($_POST['shift_type'] ?? '');

    if (empty($doctor_id) || empty($date) || empty($time)) {
        $error = "Please select a doctor, date and time.";
    } elseif ($date < date("Y-m-d")) {
        $error = "Past dates are not allowed.";
    } else {
        $allowed = false;
        if (isset($allSchedules[$doctor_id])) {
            foreach ($allSchedules[$doctor_id] as $schedule) {
                if ($schedule['date'] === $date) { $allowed = true; break; }
            }
        }

        if (!$allowed) {
            $error = "Selected doctor is not available on that day.";
        } else {
            // Server-side shift time validation
            $timeValid = true;
            if (!empty($shift) && !empty($time)) {
                list($h, $m) = array_map('intval', explode(':', $time));
                $mins = $h * 60 + $m;
                $shiftLower = strtolower($shift);
                if ($shiftLower === 'morning') {
                    $timeValid = ($mins >= 6 * 60 && $mins <= 11 * 60 + 59);
                } elseif ($shiftLower === 'afternoon') {
                    $timeValid = ($mins >= 12 * 60 && $mins <= 19 * 60 + 59);
                } elseif ($shiftLower === 'night') {
                    $timeValid = ($mins >= 20 * 60 || $mins < 6 * 60);
                }
            }

            if (!$timeValid) {
                $shiftLabels = ['morning' => '06:00 AM – 11:59 AM', 'afternoon' => '12:00 PM – 07:59 PM', 'night' => '08:00 PM – 05:59 AM'];
                $label = $shiftLabels[strtolower($shift)] ?? '';
                $error = "Selected time is outside the $shift shift hours ($label).";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO appointments (patient_id, doctor_id, preferred_date, preferred_time, reason)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("sssss", $patient_id, $doctor_id, $date, $time, $reason);
                $success = $stmt->execute()
                    ? "Appointment request submitted successfully."
                    : "Failed to submit appointment.";
            }
        }
    }
}

// Fetch patient's appointments
$appointments = [];
$stmt = $conn->prepare("
    SELECT a.*, d.full_name AS doctor_name, d.type AS doctor_type, d.specialization
    FROM appointments a
    LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
    WHERE a.patient_id = ?
    ORDER BY a.created_at DESC
");
$stmt->bind_param("s", $patient_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $appointments[] = $row;

// Fetch doctors grouped by type
$generalDoctors    = [];
$specialistDoctors = [];
$res = $conn->query("SELECT doctor_id, full_name, type, specialization FROM doctors ORDER BY full_name ASC");
while ($d = $res->fetch_assoc()) {
    if ($d['type'] === 'specialist') $specialistDoctors[] = $d;
    else $generalDoctors[] = $d;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse | Appointments</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }

/* ── Header ── */
header {
    background:#0f4c81;
    color:#fff;
    padding:15px 30px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    box-shadow:0 2px 8px rgba(0,0,0,.15);
}
header h2 { font-size:22px; letter-spacing:.5px; }
header .user-info { font-size:14px; opacity:.9; }
header .user-info strong { font-weight:700; }

/* ── Layout ── */
.container { display:flex; min-height:calc(100vh - 62px); }

/* ── Sidebar ── */
.sidebar { width:240px; background:#fff; box-shadow:2px 0 10px rgba(0,0,0,.05); padding-top:20px; flex-shrink:0; }
.sidebar ul { list-style:none; }
.sidebar ul li { border-left:4px solid transparent; transition:.2s; }
.sidebar ul li a {
    display:flex; align-items:center; gap:10px;
    padding:15px 25px;
    text-decoration:none; color:#444; font-size:14px;
}
.sidebar ul li:hover, .sidebar ul li.active {
    background:#f0f6ff;
    border-left-color:#0f4c81;
}
.sidebar ul li.active a, .sidebar ul li:hover a { color:#0f4c81; font-weight:600; }

/* ── Main ── */
.main-content { flex:1; padding:30px; overflow-x:auto; }

.page-title { margin-bottom:24px; }
.page-title h2 { font-size:22px; color:#0f4c81; margin-bottom:4px; }
.page-title p  { color:#777; font-size:14px; }

/* ── Card ── */
.card {
    background:#fff;
    padding:28px;
    border-radius:12px;
    margin-bottom:28px;
    box-shadow:0 4px 18px rgba(0,0,0,.06);
}
.card-header {
    display:flex; align-items:center; gap:10px;
    margin-bottom:22px;
    padding-bottom:14px;
    border-bottom:1px solid #eef0f5;
}
.card-header i { color:#0f4c81; font-size:18px; }
.card-header h3 { font-size:17px; color:#0f4c81; }

/* ── Form ── */
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:18px; }
.form-group { display:flex; flex-direction:column; gap:6px; }
.form-group label { font-size:13px; font-weight:600; color:#444; }

.form-group input,
.form-group select,
.form-group textarea {
    padding:10px 12px;
    border:1px solid #d5dde8;
    border-radius:8px;
    font-size:14px;
    color:#333;
    background:#fff;
    transition:border-color .2s, box-shadow .2s;
    width:100%;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline:none;
    border-color:#1fa2ff;
    box-shadow:0 0 0 3px rgba(31,162,255,.12);
}
.form-group input[readonly] {
    background:#f5f7fa;
    color:#666;
    cursor:default;
}
.form-group textarea { resize:vertical; }

/* ── Doctor type tabs ── */
.doctor-type-tabs { display:flex; gap:10px; margin-bottom:4px; }
.doctor-type-tabs button {
    background:#eef2fb;
    color:#0f4c81;
    border:2px solid transparent;
    padding:9px 22px;
    border-radius:8px;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    transition:.2s;
}
.doctor-type-tabs button.active {
    background:#0f4c81;
    color:#fff;
    border-color:#0f4c81;
}
.doctor-type-tabs button:hover:not(.active) { background:#d8e6ff; }

/* ── Schedule info box ── */
.schedule-info {
    background:#f4f8ff;
    border:1px solid #ccdeff;
    padding:18px 20px;
    border-radius:10px;
    margin-top:4px;
}
.schedule-info .form-grid { grid-template-columns:1fr 1fr; }

/* ── Shift badge inside input ── */
.shift-badge {
    display:inline-block;
    padding:4px 12px;
    border-radius:20px;
    font-size:12px;
    font-weight:700;
    letter-spacing:.4px;
    text-transform:uppercase;
}
.shift-morning   { background:#fff3cd; color:#856404; }
.shift-afternoon { background:#d1ecf1; color:#0c5460; }
.shift-night     { background:#e2d9f3; color:#4a2080; }

/* ── Time hint ── */
.time-hint {
    font-size:12px;
    color:#666;
    margin-top:-2px;
    display:none;
}
.time-hint.visible { display:block; }
.time-hint.error   { color:#dc3545; }
.time-hint.info    { color:#0c6ebd; }

/* ── Alerts ── */
.alert {
    display:flex; align-items:center; gap:10px;
    padding:13px 16px;
    border-radius:8px;
    font-size:14px;
    margin-bottom:18px;
}
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* ── Submit button ── */
.submit-btn {
    margin-top:8px;
    padding:12px 28px;
    background:#0f4c81;
    color:#fff;
    border:none;
    border-radius:8px;
    font-size:14px;
    font-weight:600;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:8px;
    transition:.2s;
}
.submit-btn:hover { background:#0c3d67; }

/* ── Table ── */
.table-wrapper { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:700px; }
th {
    background:#0f4c81;
    color:#fff;
    padding:13px 14px;
    text-align:left;
    font-size:13px;
    font-weight:600;
}
th:first-child { border-radius:8px 0 0 0; }
th:last-child  { border-radius:0 8px 0 0; }
td { padding:12px 14px; border-bottom:1px solid #eef0f5; font-size:13px; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td { background:#f9fbff; }
.empty { text-align:center; color:#999; padding:30px; }

/* ── Status pills ── */
.status-pill {
    display:inline-block;
    padding:4px 12px;
    border-radius:20px;
    font-size:12px;
    font-weight:700;
    text-transform:capitalize;
}
.status-pending     { background:#fff3cd; color:#856404; }
.status-approved    { background:#d4edda; color:#155724; }
.status-rejected    { background:#f8d7da; color:#721c24; }
.status-rescheduled { background:#cce5ff; color:#004085; }

/* ── Doctor badges ── */
.badge-general    { background:#eef5ff; color:#0f4c81; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-specialist { background:#fff3cd; color:#856404; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
</style>
</head>
<body>

<header>
    <h2>MediPulse</h2>
    <div class="user-info">Logged in as <strong><?= htmlspecialchars($patientName) ?></strong></div>
</header>

<div class="container">
    <nav class="sidebar">
        <ul>
            <li><a href="patient-dash.php"><i class="fa-solid fa-gauge"></i>Dashboard</a></li>
            <li class="active"><a href="appointment.php"><i class="fa-solid fa-calendar-check"></i>Appointments</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <div class="page-title">
            <h2>Appointments</h2>
            <p>Book a new appointment or track existing ones</p>
        </div>

        <!-- ── Request Form ── -->
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-calendar-plus"></i>
                <h3>Request Appointment</h3>
            </div>

            <?php if ($success): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="appointmentForm">
                <!-- Hidden shift for server validation -->
                <input type="hidden" name="shift_type" id="hiddenShift">

                <!-- Doctor Type Tabs -->
                <div class="form-group" style="margin-bottom:18px;">
                    <label>Doctor Type</label>
                    <div class="doctor-type-tabs">
                        <button type="button" class="active" id="tabGeneral"  onclick="switchType('general')">
                            <i class="fa-solid fa-user-doctor"></i> General Doctor
                        </button>
                        <button type="button" id="tabSpecial" onclick="switchType('special')">
                            <i class="fa-solid fa-stethoscope"></i> Specialist
                        </button>
                    </div>
                </div>

                <div class="form-grid">
                    <!-- Doctor Select -->
                    <div class="form-group">
                        <label for="doctorSelect">Select Doctor</label>
                        <select name="doctor_id" id="doctorSelect" required>
                            <option value="">-- Select Doctor --</option>
                            <optgroup label="General Doctors" id="generalGroup">
                                <?php foreach ($generalDoctors as $d): ?>
                                <option value="<?= htmlspecialchars($d['doctor_id']) ?>">
                                    <?= htmlspecialchars($d['full_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Specialists" id="specialGroup" style="display:none">
                                <?php foreach ($specialistDoctors as $d): ?>
                                <option value="<?= htmlspecialchars($d['doctor_id']) ?>">
                                    <?= htmlspecialchars($d['full_name']) ?> — <?= htmlspecialchars($d['specialization']) ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <!-- Available Dates (shown after doctor picked) -->
                    <div class="form-group" id="dateGroup" style="display:none;">
                        <label for="availableDates">Available Date</label>
                        <select name="preferred_date" id="availableDates" required>
                            <option value="">-- Select Available Date --</option>
                        </select>
                    </div>
                </div>

                <!-- Schedule Info (shown after date picked) -->
                <div class="schedule-info" id="scheduleInfo" style="display:none; margin-top:18px;">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Doctor's Shift</label>
                            <div id="shiftBadgeWrapper" style="padding:6px 0;"></div>
                        </div>
                        <div class="form-group">
                            <label>Shift Hours</label>
                            <input type="text" id="shiftHoursDisplay" readonly>
                        </div>
                    </div>
                </div>

                <!-- Time & Reason (shown after date picked) -->
                <div id="timeReasonGroup" style="display:none; margin-top:18px;">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="preferredTime">Preferred Time</label>
                            <input type="time" name="preferred_time" id="preferredTime" required>
                            <span class="time-hint" id="timeHint"></span>
                        </div>
                        <div class="form-group">
                            <label for="reason">Reason for Visit</label>
                            <textarea name="reason" id="reason" rows="3" placeholder="Briefly describe your symptoms or reason..."></textarea>
                        </div>
                    </div>
                    <button type="submit" name="request_appointment" class="submit-btn">
                        <i class="fa-solid fa-paper-plane"></i> Submit Appointment
                    </button>
                </div>
            </form>
        </div>

        <!-- ── My Appointments Table ── -->
        <div class="card">
            <div class="card-header">
                <i class="fa-solid fa-list-check"></i>
                <h3>My Appointments</h3>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Doctor</th>
                            <th>Type</th>
                            <th>Preferred Date</th>
                            <th>Preferred Time</th>
                            <th>Scheduled Date &amp; Time</th>
                            <th>Status</th>
                            <th>Receptionist Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($appointments) > 0): ?>
                        <?php foreach ($appointments as $app): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($app['doctor_name'] ?? '-') ?></strong>
                                <?php if (!empty($app['specialization'])): ?>
                                    <br><small style="color:#888;"><?= htmlspecialchars($app['specialization']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-<?= htmlspecialchars($app['doctor_type'] ?? 'general') ?>">
                                    <?= ucfirst(htmlspecialchars($app['doctor_type'] ?? 'General')) ?>
                                </span>
                            </td>
                            <td><?= date("d M Y", strtotime($app['preferred_date'])) ?></td>
                            <td><?= date("h:i A", strtotime($app['preferred_time'])) ?></td>
                            <td>
                                <?php if (!empty($app['scheduled_date']) && !empty($app['scheduled_time'])): ?>
                                    <?= date("d M Y", strtotime($app['scheduled_date'])) ?>
                                    <br><small><?= date("h:i A", strtotime($app['scheduled_time'])) ?></small>
                                <?php else: ?>
                                    <span style="color:#bbb;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-pill status-<?= strtolower(htmlspecialchars($app['status'])) ?>">
                                    <?= htmlspecialchars($app['status']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($app['receptionist_note'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty">
                                <i class="fa-solid fa-calendar-xmark" style="font-size:28px;color:#ddd;display:block;margin-bottom:8px;"></i>
                                No appointments found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
const allSchedules = <?= json_encode($allSchedules, JSON_HEX_TAG) ?>;

// Shift definitions
const SHIFTS = {
    morning:   { label:'Morning',   hours:'06:00 AM – 11:59 AM', min:'06:00', max:'11:59', cls:'shift-morning'   },
    afternoon: { label:'Afternoon', hours:'12:00 PM – 07:59 PM', min:'12:00', max:'19:59', cls:'shift-afternoon' },
    night:     { label:'Night',     hours:'08:00 PM – 05:59 AM', min:'20:00', max:'23:59', cls:'shift-night'     }
};

const doctorSelect    = document.getElementById('doctorSelect');
const generalGroup    = document.getElementById('generalGroup');
const specialGroup    = document.getElementById('specialGroup');
const dateGroup       = document.getElementById('dateGroup');
const availableDates  = document.getElementById('availableDates');
const scheduleInfo    = document.getElementById('scheduleInfo');
const shiftBadgeWrap  = document.getElementById('shiftBadgeWrapper');
const shiftHoursDisp  = document.getElementById('shiftHoursDisplay');
const timeReasonGroup = document.getElementById('timeReasonGroup');
const preferredTime   = document.getElementById('preferredTime');
const timeHint        = document.getElementById('timeHint');
const hiddenShift     = document.getElementById('hiddenShift');
const tabGeneral      = document.getElementById('tabGeneral');
const tabSpecial      = document.getElementById('tabSpecial');

let currentShift = '';

// ── Switch doctor type tabs ────────────────────────────────────────────
function switchType(type) {
    doctorSelect.value = '';
    resetDateAndBelow();

    if (type === 'general') {
        generalGroup.style.display = '';
        specialGroup.style.display = 'none';
        tabGeneral.classList.add('active');
        tabSpecial.classList.remove('active');
    } else {
        generalGroup.style.display = 'none';
        specialGroup.style.display = '';
        tabSpecial.classList.add('active');
        tabGeneral.classList.remove('active');
    }
}

// ── Reset helpers ──────────────────────────────────────────────────────
function resetDateAndBelow() {
    dateGroup.style.display      = 'none';
    availableDates.innerHTML     = '<option value="">-- Select Available Date --</option>';
    scheduleInfo.style.display   = 'none';
    timeReasonGroup.style.display = 'none';
    preferredTime.value          = '';
    preferredTime.removeAttribute('min');
    preferredTime.removeAttribute('max');
    timeHint.className           = 'time-hint';
    timeHint.textContent         = '';
    hiddenShift.value            = '';
    currentShift                 = '';
}

function resetTimeAndBelow() {
    scheduleInfo.style.display    = 'none';
    timeReasonGroup.style.display = 'none';
    preferredTime.value           = '';
    preferredTime.removeAttribute('min');
    preferredTime.removeAttribute('max');
    timeHint.className            = 'time-hint';
    timeHint.textContent          = '';
    hiddenShift.value             = '';
    currentShift                  = '';
}

// ── Doctor change: populate dates ──────────────────────────────────────
doctorSelect.addEventListener('change', function () {
    resetDateAndBelow();
    const doctorId = this.value;
    if (!doctorId || !allSchedules[doctorId]) return;

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    let count = 0;

    allSchedules[doctorId].forEach(function (schedule) {
        const d = new Date(schedule.date + 'T00:00:00');
        if (d < today) return;
        count++;
        const opt = document.createElement('option');
        opt.value          = schedule.date;
        opt.dataset.shift  = schedule.shift;
        opt.textContent    = d.toLocaleDateString('en-GB', { weekday:'long', day:'2-digit', month:'short', year:'numeric' });
        availableDates.appendChild(opt);
    });

    if (count === 0) {
        const opt = document.createElement('option');
        opt.textContent = 'No available schedules';
        opt.disabled = true;
        availableDates.appendChild(opt);
    }

    dateGroup.style.display = 'block';
});

// ── Date change: show shift info and time picker ───────────────────────
availableDates.addEventListener('change', function () {
    resetTimeAndBelow();
    const selected = this.options[this.selectedIndex];
    if (!this.value || !selected.dataset.shift) return;

    const shift = selected.dataset.shift.toLowerCase().trim();
    const info  = SHIFTS[shift];
    currentShift      = shift;
    hiddenShift.value = shift;

    // Show shift badge
    shiftBadgeWrap.innerHTML = `<span class="shift-badge ${info ? info.cls : ''}">${selected.dataset.shift}</span>`;
    shiftHoursDisp.value     = info ? info.hours : '';
    scheduleInfo.style.display = 'block';

    // Set time constraints
    if (shift === 'morning' || shift === 'afternoon') {
        preferredTime.min = info.min;
        preferredTime.max = info.max;
    } else {
        // Night crosses midnight — remove native constraints, validate on submit
        preferredTime.removeAttribute('min');
        preferredTime.removeAttribute('max');
    }

    // Show hint
    timeHint.textContent  = info ? `Select a time between ${info.hours}` : '';
    timeHint.className    = 'time-hint info visible';

    timeReasonGroup.style.display = 'block';
});

// ── Time input: live validation hint ──────────────────────────────────
preferredTime.addEventListener('change', function () {
    if (!currentShift || !this.value) return;
    const [h, m]  = this.value.split(':').map(Number);
    const mins    = h * 60 + m;
    const info    = SHIFTS[currentShift];
    let valid     = true;

    if (currentShift === 'morning') {
        valid = (mins >= 6 * 60 && mins <= 11 * 60 + 59);
    } else if (currentShift === 'afternoon') {
        valid = (mins >= 12 * 60 && mins <= 19 * 60 + 59);
    } else if (currentShift === 'night') {
        valid = (mins >= 20 * 60 || mins < 6 * 60);
    }

    if (valid) {
        timeHint.textContent = `✓ Time is within the ${info.label} shift`;
        timeHint.className   = 'time-hint info visible';
    } else {
        timeHint.textContent = `✗ Please choose a time within ${info.hours}`;
        timeHint.className   = 'time-hint error visible';
    }
});

// ── Form submit: block invalid times client-side ───────────────────────
document.getElementById('appointmentForm').addEventListener('submit', function (e) {
    if (!currentShift || !preferredTime.value) return;
    const [h, m] = preferredTime.value.split(':').map(Number);
    const mins   = h * 60 + m;
    let valid    = true;
    const info   = SHIFTS[currentShift];

    if (currentShift === 'morning') {
        valid = (mins >= 6 * 60 && mins <= 11 * 60 + 59);
    } else if (currentShift === 'afternoon') {
        valid = (mins >= 12 * 60 && mins <= 19 * 60 + 59);
    } else if (currentShift === 'night') {
        valid = (mins >= 20 * 60 || mins < 6 * 60);
    }

    if (!valid) {
        e.preventDefault();
        timeHint.textContent = `✗ Please choose a time within the ${info.label} shift: ${info.hours}`;
        timeHint.className   = 'time-hint error visible';
        preferredTime.focus();
    }
});
</script>
</body>
</html>