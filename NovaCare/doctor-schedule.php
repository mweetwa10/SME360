<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "Unauthorized access";
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$today    = date('Y-m-d');
$monday   = date('Y-m-d', strtotime('monday this week'));
$sunday   = date('Y-m-d', strtotime('sunday this week'));

$days = [];
$current = strtotime($monday);
while ($current <= strtotime($sunday)) {
    $days[] = ['date' => date('Y-m-d', $current), 'day' => date('D', $current)];
    $current = strtotime('+1 day', $current);
}

$success = "";
$error   = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor_id     = $_POST['doctor_id']     ?? '';
    $shift_type    = $_POST['shift_type']    ?? '';
    $selected_days = $_POST['schedule_days'] ?? [];

    if (empty($doctor_id) || empty($shift_type) || empty($selected_days)) {
        $error = "Please complete all required fields.";
    } else {

        // Determine if this doctor is a specialist
        $typeRes = $conn->prepare("SELECT type FROM doctors WHERE doctor_id = ?");
        $typeRes->bind_param("s", $doctor_id);
        $typeRes->execute();
        $typeRow     = $typeRes->get_result()->fetch_assoc();
        $typeRes->close();
        $is_specialist = ($typeRow['type'] ?? 'general') === 'specialist';

        $inserted = 0;

        foreach ($selected_days as $schedule_date) {

            // Only block if assigning a GENERAL doctor and a general doctor already has this shift on this date
            if (!$is_specialist) {
                $conflict = $conn->prepare("
                    SELECT s.schedule_id
                    FROM doctor_weekly_schedules s
                    JOIN doctors d ON s.doctor_id = d.doctor_id
                    WHERE s.schedule_date = ?
                    AND s.shift_type = ?
                    AND d.type = 'general'
                ");
                $conflict->bind_param("ss", $schedule_date, $shift_type);
                $conflict->execute();
                $conflictRows = $conflict->get_result()->num_rows;
                $conflict->close();

                if ($conflictRows > 0) {
                    $error = "A general doctor is already assigned to the $shift_type shift on " . date('D d M Y', strtotime($schedule_date)) . ".";
                    continue;
                }
            }

            // Check if this specific doctor already has any shift on this date
            $check = $conn->prepare("
                SELECT schedule_id FROM doctor_weekly_schedules
                WHERE doctor_id = ? AND schedule_date = ?
            ");
            $check->bind_param("ss", $doctor_id, $schedule_date);
            $check->execute();
            $checkRows = $check->get_result()->num_rows;
            $check->close();

            if ($checkRows > 0) {
                $error = "This doctor already has a shift on " . date('D d M Y', strtotime($schedule_date)) . ".";
                continue;
            }

            $insert = $conn->prepare("
                INSERT INTO doctor_weekly_schedules (doctor_id, shift_type, schedule_date, week_start, week_end, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert->bind_param("ssssss", $doctor_id, $shift_type, $schedule_date, $monday, $sunday, $admin_id);
            if ($insert->execute()) $inserted++;
            $insert->close();
        }

        if ($inserted > 0) $success = "Weekly doctor schedule saved successfully.";
    }
}

$doctors = [];
$result = $conn->query("SELECT doctor_id, full_name, type, specialization FROM doctors ORDER BY full_name ASC");
while ($row = $result->fetch_assoc()) $doctors[] = $row;

$schedules = [];
$scheduleQuery = $conn->query("
    SELECT s.*, d.full_name, d.type AS doctor_type, d.specialization
    FROM doctor_weekly_schedules s
    JOIN doctors d ON s.doctor_id = d.doctor_id
    WHERE s.week_start = '$monday'
    ORDER BY s.schedule_date ASC
");
while ($row = $scheduleQuery->fetch_assoc()) $schedules[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Weekly Doctor Scheduling</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }
header { background:#0f4c81; color:#fff; padding:18px 30px; display:flex; justify-content:space-between; align-items:center; }
.container { display:flex; min-height:100vh; }
.sidebar { width:240px; background:#fff; box-shadow:2px 0 10px rgba(0,0,0,.05); padding-top:20px; }
.sidebar ul { list-style:none; }
.sidebar ul li { padding:15px 25px; border-left:4px solid transparent; }
.sidebar ul li:hover, .sidebar ul li.active { background:#f0f6ff; border-left:4px solid #0f4c81; }
.sidebar ul li a { text-decoration:none; color:#333; display:block; width:100%; }
.main-content { flex:1; padding:30px; }
.card { background:#fff; padding:25px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,.05); margin-bottom:30px; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:20px; margin-bottom:25px; }
.form-group label { display:block; margin-bottom:8px; font-weight:600; font-size:14px; }
.form-group select { width:100%; padding:12px; border:1px solid #ccc; border-radius:8px; }
.calendar { display:grid; grid-template-columns:repeat(7,1fr); gap:15px; margin-top:20px; }
.day-card { background:#f9fbff; border:2px solid #dce6f2; border-radius:12px; padding:20px; text-align:center; cursor:pointer; transition:.3s; }
.day-card:hover { transform:translateY(-3px); border-color:#0f4c81; }
.day-card.active { background:#0f4c81; color:#fff; border-color:#0f4c81; }
.day-card input { display:none; }
.day-name { font-size:16px; font-weight:700; margin-bottom:10px; }
.day-date { font-size:13px; }
.submit-btn { margin-top:30px; background:#0f4c81; color:#fff; border:none; padding:14px 25px; border-radius:8px; cursor:pointer; font-size:15px; font-weight:600; }
.submit-btn:hover { background:#0b3c66; }
.alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; }
.success { background:#d4edda; color:#155724; }
.error { background:#f8d7da; color:#721c24; }
.table-section { margin-top:30px; }
table { width:100%; border-collapse:collapse; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 8px 20px rgba(0,0,0,.05); }
th, td { padding:14px; border-bottom:1px solid #eee; text-align:left; font-size:14px; }
th { background:#0f4c81; color:#fff; }
tr:hover { background:#f9fbff; }
.shift-badge { background:#eef5ff; color:#0f4c81; padding:6px 12px; border-radius:20px; font-size:12px; font-weight:600; }
.badge-general    { background:#eef5ff; color:#0f4c81; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-specialist { background:#fff3cd; color:#856404; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
@media(max-width:900px) { .calendar { grid-template-columns:repeat(2,1fr); } }
@media(max-width:600px) { .container { flex-direction:column; } .sidebar { width:100%; } .calendar { grid-template-columns:1fr; } }
</style>
</head>
<body>
<header>
    <h2>MediPulse</h2>
    <div>Week: <strong><?= date('d M', strtotime($monday)) ?> - <?= date('d M Y', strtotime($sunday)) ?></strong></div>
</header>
<div class="container">
    <nav class="sidebar">
        <ul>
            <li><a href="admin-dash.php"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
            <li class="active"><a href="doctor-schedule.php"><i class="fa-solid fa-calendar-days"></i> Doctor Schedules</a></li>
        </ul>
    </nav>
    <main class="main-content">
        <div class="card">
            <h2>Create Weekly Schedule</h2><br>
            <?php if ($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>
            <?php if ($error):   ?><div class="alert error"><?= $error ?></div><?php endif; ?>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Select Doctor</label>
                        <select name="doctor_id" required>
                            <option value="">-- Select Doctor --</option>
                            <optgroup label="General Doctors">
                                <?php foreach ($doctors as $d): if ($d['type'] !== 'general') continue; ?>
                                <option value="<?= $d['doctor_id'] ?>"><?= htmlspecialchars($d['full_name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Specialists">
                                <?php foreach ($doctors as $d): if ($d['type'] !== 'specialist') continue; ?>
                                <option value="<?= $d['doctor_id'] ?>"><?= htmlspecialchars($d['full_name']) ?> — <?= htmlspecialchars($d['specialization']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Select Shift</label>
                        <select name="shift_type" required>
                            <option value="">-- Select Shift --</option>
                            <option value="Morning">Morning Shift</option>
                            <option value="Afternoon">Afternoon Shift</option>
                            <option value="Night">Night Shift</option>
                        </select>
                    </div>
                </div>
                <h3>Select Working Days</h3>
                <div class="calendar">
                    <?php foreach ($days as $day): ?>
                    <label class="day-card">
                        <input type="checkbox" name="schedule_days[]" value="<?= $day['date'] ?>">
                        <div class="day-name"><?= $day['day'] ?></div>
                        <div class="day-date"><?= date('d M', strtotime($day['date'])) ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="submit-btn"><i class="fa-solid fa-calendar-check"></i> Save Weekly Schedule</button>
            </form>
        </div>
        <div class="table-section">
            <h2>Current Week Schedule</h2><br>
            <table>
                <thead>
                    <tr>
                        <th>Doctor</th>
                        <th>Type</th>
                        <th>Shift</th>
                        <th>Schedule Date</th>
                        <th>Week</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($schedules) > 0): ?>
                    <?php foreach ($schedules as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['full_name']) ?><?= $s['specialization'] ? ' — ' . htmlspecialchars($s['specialization']) : '' ?></td>
                        <td><span class="badge-<?= $s['doctor_type'] ?>"><?= ucfirst($s['doctor_type']) ?></span></td>
                        <td><span class="shift-badge"><?= htmlspecialchars($s['shift_type']) ?></span></td>
                        <td><?= date('D d M Y', strtotime($s['schedule_date'])) ?></td>
                        <td><?= date('d M', strtotime($s['week_start'])) ?> - <?= date('d M Y', strtotime($s['week_end'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="5">No schedules created for this week.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<script>
document.querySelectorAll('.day-card').forEach(card => {
    card.addEventListener('click', () => {
        const checkbox = card.querySelector('input');
        setTimeout(() => {
            card.classList.toggle('active', checkbox.checked);
        }, 10);
    });
});
</script>
</body>
</html>