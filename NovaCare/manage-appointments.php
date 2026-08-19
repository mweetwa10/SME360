<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Receptionist') {
    header('Location: login.php');
    exit;
}

// ── Handle POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointment_id = (int)$_POST['appointment_id'];
    $scheduled_date = $_POST['scheduled_date'];
    $scheduled_time = $_POST['scheduled_time'];
    $action         = $_POST['action'];

    if (!in_array($action, ['Approved', 'Rescheduled', 'Completed'])) {
        $_SESSION['error'] = 'Invalid status selected.';
        header('Location: manage-appointments.php');
        exit;
    }

    if ($action !== 'Completed') {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM appointments
            WHERE scheduled_date = ? AND scheduled_time = ?
            AND status = 'Approved' AND appointment_id != ?
        ");
        $stmt->bind_param("ssi", $scheduled_date, $scheduled_time, $appointment_id);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count > 0) {
            $_SESSION['error'] = 'Selected date and time already booked.';
            header('Location: manage-appointments.php');
            exit;
        }
    }

    $stmt = $conn->prepare("
        UPDATE appointments SET status = ?, scheduled_date = ?, scheduled_time = ?
        WHERE appointment_id = ?
    ");
    $stmt->bind_param("sssi", $action, $scheduled_date, $scheduled_time, $appointment_id);
    $_SESSION[$stmt->execute() ? 'success' : 'error'] = $stmt->execute()
        ? 'Appointment updated successfully.'
        : 'Failed to update appointment.';
    $stmt->close();

    header('Location: manage-appointments.php');
    exit;
}

// ── Filters & pagination ───────────────────────────────────────────────
$search       = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 8;
$offset       = ($page - 1) * $limit;

$where  = "WHERE 1=1";
$params = [];
$types  = '';

if (!empty($search)) {
    $where   .= " AND p.patient_id LIKE ?";
    $params[] = "%{$search}%";
    $types   .= 's';
}
if (!empty($statusFilter)) {
    $where   .= " AND a.status = ?";
    $params[] = $statusFilter;
    $types   .= 's';
}

// ── Count ──────────────────────────────────────────────────────────────
$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total FROM appointments a
    JOIN patients p ON a.patient_id = p.patient_id $where
");
if (!empty($params)) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows  = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);
$countStmt->close();

// ── Appointments (now includes doctor) ────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        a.appointment_id,
        p.patient_id,
        p.full_name   AS patient,
        p.phone,
        a.preferred_date,
        a.preferred_time,
        a.scheduled_date,
        a.scheduled_time,
        a.status,
        d.full_name   AS doctor_name,
        d.email       AS doctor_email,
        d.phone       AS doctor_phone,
        d.type        AS doctor_type,
        d.specialization
    FROM appointments a
    JOIN patients p  ON a.patient_id  = p.patient_id
    LEFT JOIN doctors d ON a.doctor_id = d.doctor_id
    $where
    ORDER BY COALESCE(a.scheduled_date, a.preferred_date) DESC,
             COALESCE(a.scheduled_time, a.preferred_time) DESC
    LIMIT ? OFFSET ?
");
$allParams   = array_merge($params, [$limit, $offset]);
$allTypes    = $types . 'ii';
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Status summary cards ───────────────────────────────────────────────
$counts = ['Total' => $totalRows, 'Pending' => 0, 'Approved' => 0, 'Rescheduled' => 0];
$statusQuery = $conn->query("SELECT status, COUNT(*) AS total FROM appointments GROUP BY status");
while ($s = $statusQuery->fetch_assoc()) {
    if (isset($counts[$s['status']])) $counts[$s['status']] = $s['total'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse | Manage Appointments</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }

header { background:#0f4c81; color:#fff; padding:15px 30px; display:flex; justify-content:space-between; align-items:center; }
.header-left h1 { font-size:24px; }

.container { display:flex; min-height:calc(100vh - 70px); }

.sidebar { width:250px; background:#fff; box-shadow:2px 0 10px rgba(0,0,0,.05); padding-top:20px; }
.sidebar ul { list-style:none; }
.sidebar ul li { border-left:4px solid transparent; transition:.3s; }
.sidebar ul li a { display:flex; align-items:center; gap:12px; padding:16px 25px; text-decoration:none; color:#333; font-size:14px; font-weight:500; }
.sidebar ul li.active { background:#f0f6ff; border-left-color:#0f4c81; }
.sidebar ul li.active a { color:#0f4c81; font-weight:600; }
.sidebar ul li:hover { background:#f7faff; }

.main-content { flex:1; padding:30px; }
.page-header { margin-bottom:25px; }
.page-header h2 { font-size:28px; color:#0f4c81; margin-bottom:6px; }
.page-header p { color:#666; font-size:14px; }

.cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:20px; margin-bottom:30px; }
.card { background:#fff; padding:22px; border-radius:14px; box-shadow:0 8px 20px rgba(0,0,0,.05); position:relative; overflow:hidden; }
.card::before { content:''; position:absolute; width:70px; height:70px; background:rgba(15,76,129,.08); border-radius:50%; top:-20px; right:-20px; }
.card h3 { font-size:14px; color:#666; margin-bottom:12px; }
.card span { font-size:32px; font-weight:700; color:#0f4c81; }
.card i { position:absolute; right:20px; bottom:20px; font-size:28px; color:#d6e5f5; }

.main-card { background:#fff; border-radius:16px; box-shadow:0 8px 20px rgba(0,0,0,.05); overflow:hidden; }
.card-header { padding:22px 25px; border-bottom:1px solid #eee; }
.card-header h3 { color:#0f4c81; font-size:22px; margin-bottom:5px; }
.card-header p { color:#666; font-size:14px; }

.alert-success { background:#e6fffa; color:#065f46; padding:14px; border-radius:10px; margin:20px; font-weight:500; }
.alert-error   { background:#ffe6e6; color:#7f1d1d; padding:14px; border-radius:10px; margin:20px; font-weight:500; }

.table-controls { padding:20px 25px 0; }
.search-form { display:flex; gap:12px; flex-wrap:wrap; }
.search-form input,
.search-form select { padding:11px 12px; border:1px solid #ccc; border-radius:8px; min-width:220px; outline:none; }
.search-form input:focus,
.search-form select:focus { border-color:#0f4c81; }

.table-wrapper { overflow-x:auto; max-height:650px; overflow-y:auto; border-radius:12px; }
table { width:100%; border-collapse:collapse; background:#fff; }
th { background:#0f4c81; color:#fff; padding:10px 12px; font-size:12px; text-align:left; position:sticky; top:0; z-index:2; white-space:nowrap; }
td { padding:8px 12px; font-size:12px; border-bottom:1px solid #edf2f7; vertical-align:middle; }
tr:hover { background:#f9fbff; }

.patient-name { font-weight:600; color:#222; margin-bottom:4px; }
.patient-phone { color:#777; font-size:12px; }

/* Doctor cell */
.doctor-name { font-weight:600; color:#222; margin-bottom:3px; }
.doctor-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; margin-bottom:5px; }
.badge-general    { background:#eef5ff; color:#0f4c81; }
.badge-specialist { background:#fff3cd; color:#856404; }
.doctor-actions { display:flex; gap:5px; margin-top:4px; }
.contact-btn { display:inline-flex; align-items:center; gap:4px; padding:4px 9px; border-radius:6px; font-size:11px; font-weight:600; text-decoration:none; border:none; cursor:pointer; transition:.2s; }
.contact-btn.call  { background:#e8f5e9; color:#1b5e20; }
.contact-btn.email { background:#e3f2fd; color:#0d47a1; }
.contact-btn.call:hover  { background:#c8e6c9; }
.contact-btn.email:hover { background:#bbdefb; }

.status { padding:7px 14px; border-radius:30px; font-size:12px; font-weight:700; display:inline-block; }
.pending     { background:#fff7e6; color:#b7791f; }
.approved    { background:#e6fffa; color:#065f46; }
.rescheduled { background:#e8f1ff; color:#0f4c81; }

.form-inline { display:flex; flex-direction:column; gap:8px; min-width:230px; }
.form-inline input,
.form-inline select { width:100%; padding:10px; border:1px solid #d8d8d8; border-radius:8px; outline:none; font-size:13px; }
.form-inline input:focus,
.form-inline select:focus { border-color:#0f4c81; }

.btn { background:#0f4c81; color:#fff; border:none; padding:11px 16px; border-radius:8px; cursor:pointer; font-weight:600; transition:.3s; font-size:13px; }
.btn:hover { background:#0b3a63; }

.pagination { display:flex; gap:10px; padding:25px; justify-content:center; flex-wrap:wrap; }
.pagination a { text-decoration:none; padding:10px 15px; background:#f0f4fa; color:#0f4c81; border-radius:8px; font-weight:600; }
.pagination a.active-page { background:#0f4c81; color:#fff; }

.empty { text-align:center; padding:30px; color:#777; }

@media(max-width:900px) {
    .sidebar { display:none; }
    .main-content { padding:20px; }
    .cards { grid-template-columns:1fr; }
}
</style>
</head>
<body>

<header>
    <div class="header-left"><h1>MediPulse</h1></div>
    <div>Logged in as: <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong></div>
</header>

<div class="container">
    <nav class="sidebar">
        <ul>
            <li><a href="reception-dash.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="active"><a href="manage-appointments.php"><i class="fas fa-calendar-check"></i> Appointments</a></li>
        </ul>
    </nav>

    <main class="main-content">
        <div class="page-header">
            <h2>Manage Appointments</h2>
            <p>Approve, reschedule and manage all patient appointments</p>
        </div>

        <!-- Summary Cards -->
        <div class="cards">
            <div class="card"><h3>Total Appointments</h3><span><?= $counts['Total'] ?></span><i class="fas fa-calendar-alt"></i></div>
            <div class="card"><h3>Pending</h3><span><?= $counts['Pending'] ?></span><i class="fas fa-clock"></i></div>
            <div class="card"><h3>Approved</h3><span><?= $counts['Approved'] ?></span><i class="fas fa-circle-check"></i></div>
            <div class="card"><h3>Rescheduled</h3><span><?= $counts['Rescheduled'] ?></span><i class="fas fa-rotate"></i></div>
        </div>

        <div class="main-card">
            <div class="card-header">
                <h3>Appointment Requests</h3>
                <p>Manage and schedule patient appointments</p>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert-error"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Search & Filter -->
            <div class="table-controls">
                <form method="GET" class="search-form">
                    <input type="text" name="search" placeholder="Search Patient ID" value="<?= htmlspecialchars($search) ?>">
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="Pending"     <?= $statusFilter === 'Pending'     ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved"    <?= $statusFilter === 'Approved'    ? 'selected' : '' ?>>Approved</option>
                        <option value="Rescheduled" <?= $statusFilter === 'Rescheduled' ? 'selected' : '' ?>>Rescheduled</option>
                    </select>
                    <button type="submit" class="btn"><i class="fas fa-search"></i> Search</button>
                </form>
            </div>

            <!-- Table -->
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Doctor</th>
                            <th>Preferred Schedule</th>
                            <th>Confirmed Schedule</th>
                            <th>Status</th>
                            <th>Update Appointment</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($appointments) > 0): ?>
                        <?php foreach ($appointments as $a): ?>
                        <?php
                            $statusClass = match($a['status']) {
                                'Pending'     => 'pending',
                                'Approved'    => 'approved',
                                'Rescheduled' => 'rescheduled',
                                default       => ''
                            };
                            $isSpecialist = ($a['doctor_type'] === 'specialist');
                        ?>
                        <tr>
                            <!-- Patient -->
                            <td>
                                <div class="patient-name"><?= htmlspecialchars($a['patient']) ?></div>
                                <div class="patient-phone"><?= htmlspecialchars($a['phone']) ?></div>
                            </td>

                            <!-- Doctor -->
                            <td>
                                <?php if (!empty($a['doctor_name'])): ?>
                                    <div class="doctor-name"><?= htmlspecialchars($a['doctor_name']) ?></div>
                                    <span class="doctor-badge <?= $isSpecialist ? 'badge-specialist' : 'badge-general' ?>">
                                        <?= $isSpecialist ? 'Specialist' : 'General' ?>
                                    </span>
                                    <?php if ($isSpecialist && !empty($a['specialization'])): ?>
                                        <div style="font-size:11px;color:#888;margin-bottom:4px;"><?= htmlspecialchars($a['specialization']) ?></div>
                                    <?php endif; ?>
                                    <div class="doctor-actions">
                                        <?php if (!empty($a['doctor_phone'])): ?>
                                            <a class="contact-btn call" href="tel:<?= htmlspecialchars($a['doctor_phone']) ?>" title="Call Doctor">
                                                <i class="fas fa-phone"></i> Call
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($a['doctor_email'])): ?>
                                            <a class="contact-btn email"
                                               href="mailto:<?= htmlspecialchars($a['doctor_email']) ?>?subject=Appointment+Notification&body=Dear+Dr.+<?= urlencode($a['doctor_name']) ?>,%0A%0AThis+is+a+notification+regarding+an+appointment+scheduled+for+<?= urlencode(date('d M Y', strtotime($a['preferred_date']))) ?>+at+<?= urlencode(date('h:i A', strtotime($a['preferred_time']))) ?>.%0A%0APlease+log+in+to+MediPulse+for+full+details.%0A%0ARegards,%0AReception+Team"
                                               title="Email Doctor">
                                                <i class="fas fa-envelope"></i> Email
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color:#bbb;">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Preferred Schedule -->
                            <td>
                                <strong><?= date("d M Y", strtotime($a['preferred_date'])) ?></strong><br><br>
                                <?= date("h:i A", strtotime($a['preferred_time'])) ?>
                            </td>

                            <!-- Confirmed Schedule -->
                            <td>
                                <?php if (!empty($a['scheduled_date'])): ?>
                                    <strong><?= date("d M Y", strtotime($a['scheduled_date'])) ?></strong><br><br>
                                    <?= date("h:i A", strtotime($a['scheduled_time'])) ?>
                                <?php else: ?>
                                    <span style="color:#888;">Not Scheduled</span>
                                <?php endif; ?>
                            </td>

                            <!-- Status -->
                            <td>
                                <span class="status <?= $statusClass ?>">
                                    <?= htmlspecialchars($a['status']) ?>
                                </span>
                            </td>

                            <!-- Update Form -->
                            <td>
                                <form method="POST" class="form-inline">
                                    <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                                    <input type="date" name="scheduled_date" required value="<?= $a['scheduled_date'] ?: $a['preferred_date'] ?>">
                                    <input type="time" name="scheduled_time" required value="<?= $a['scheduled_time'] ?: $a['preferred_time'] ?>">
                                    <select name="action">
                                        <option value="Approved">Approve</option>
                                        <option value="Rescheduled">Reschedule</option>
                                    </select>
                                    <button type="submit" class="btn">Update Appointment</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="empty">No appointments found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($statusFilter) ?>"
                       class="<?= $i === $page ? 'active-page' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>