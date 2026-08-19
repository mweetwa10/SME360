<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Lab Staff') {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $lab_request_id = intval($_POST['lab_request_id']);
    $check = $conn->prepare("SELECT payment_status FROM lab_requests WHERE lab_request_id = ?");
    $check->bind_param("i", $lab_request_id);
    $check->execute();
    $result = $check->get_result();
    $row = $result->fetch_assoc();
    if ($row && $row['payment_status'] !== 'Paid') {
        $update = $conn->prepare("UPDATE lab_requests SET payment_status = 'Paid' WHERE lab_request_id = ?");
        $update->bind_param("i", $lab_request_id);
        $update->execute();
        $update->close();
        $_SESSION['success'] = "Payment recorded successfully.";
    }
    $check->close();
    header("Location: lab-dash.php");
    exit;
}

$totalTests = $conn->query("SELECT COUNT(*) total FROM lab_requests")->fetch_assoc()['total'];

$pendingTests = $conn->query("
    SELECT COUNT(*) total FROM lab_requests lr
    LEFT JOIN lab_results lres ON lr.lab_request_id = lres.lab_request_id
    WHERE lres.lab_request_id IS NULL
")->fetch_assoc()['total'];

$completedTests = $conn->query("
    SELECT COUNT(*) total FROM lab_requests lr
    INNER JOIN lab_results lres ON lr.lab_request_id = lres.lab_request_id
")->fetch_assoc()['total'];

$paidTests = $conn->query("SELECT COUNT(*) total FROM lab_requests WHERE payment_status = 'Paid'")->fetch_assoc()['total'];

$testLabels = []; $testData = [];
$chartQuery = $conn->query("SELECT test_name, COUNT(*) total FROM lab_requests GROUP BY test_name ORDER BY total DESC LIMIT 6");
while ($row = $chartQuery->fetch_assoc()) { $testLabels[] = $row['test_name']; $testData[] = $row['total']; }

$dayLabels = []; $dayData = [];
$dailyQuery = $conn->query("SELECT DATE(requested_at) day, COUNT(*) total FROM lab_requests GROUP BY DATE(requested_at) ORDER BY day ASC LIMIT 7");
while ($row = $dailyQuery->fetch_assoc()) { $dayLabels[] = date("d M", strtotime($row['day'])); $dayData[] = $row['total']; }

$requests = $conn->query("
    SELECT
        lr.lab_request_id,
        lr.test_name,
        lr.payment_status,
        lr.requested_at,
        ltp.test_price AS test_price,
        p.full_name AS patient_name,
        d.full_name AS doctor_name,
        CASE WHEN lres.lab_request_id IS NULL THEN 'Pending' ELSE 'Completed' END AS computed_status
    FROM lab_requests lr
    LEFT JOIN lab_results lres ON lr.lab_request_id = lres.lab_request_id
    LEFT JOIN patients p ON lr.patient_id = p.patient_id
    LEFT JOIN doctors d ON lr.doctor_id = d.doctor_id
    LEFT JOIN lab_test_types ltp ON lr.test_name = ltp.test_name
    ORDER BY lr.requested_at DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse — Lab Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --navy:   #0d3b6e;
    --teal:   #0e8a7a;
    --sky:    #e8f4fd;
    --ink:    #1a2332;
    --muted:  #6b7b8d;
    --border: #dde3ec;
    --bg:     #f2f5f9;
    --white:  #ffffff;
    --amber:  #f59e0b;
    --green:  #10b981;
    --red:    #ef4444;
    --mono:   'JetBrains Mono', monospace;
    --sans:   'Inter', sans-serif;
    --radius: 10px;
    --shadow: 0 1px 3px rgba(13,59,110,.07), 0 4px 16px rgba(13,59,110,.06);
}

body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--ink);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.topbar {
    height: 58px;
    background: var(--navy);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    position: sticky;
    top: 0;
    z-index: 50;
    flex-shrink: 0;
}

.topbar-logo {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #fff;
    font-size: 17px;
    font-weight: 600;
    letter-spacing: -.3px;
}

.topbar-logo .pulse-dot {
    width: 8px; height: 8px;
    background: var(--teal);
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: .6; transform: scale(1.3); }
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 14px;
    color: #cdd8e8;
    font-size: 13px;
}

.topbar-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: var(--teal);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 12px;
    color: #fff;
    flex-shrink: 0;
}

.shell {
    display: flex;
    flex: 1;
    min-height: 0;
}

.sidebar {
    width: 220px;
    background: var(--white);
    border-right: 1px solid var(--border);
    padding: 24px 0;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.nav-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--muted);
    padding: 6px 22px 4px;
    margin-top: 12px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 22px;
    font-size: 13.5px;
    color: var(--muted);
    text-decoration: none;
    border-left: 3px solid transparent;
    transition: all .15s;
}

.nav-item:hover { background: var(--bg); color: var(--navy); }

.nav-item.active {
    background: var(--sky);
    color: var(--navy);
    border-left-color: var(--navy);
    font-weight: 600;
}

.nav-item i { width: 16px; text-align: center; font-size: 14px; }

.nav-item.danger { color: #ef4444; }
.nav-item.danger:hover { background: #fef2f2; }

.main {
    flex: 1;
    padding: 28px;
    overflow-y: auto;
    min-width: 0;
}

.page-title {
    font-size: 20px;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 4px;
}

.page-sub {
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 24px;
}

.alert {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    border-radius: var(--radius);
    font-size: 13.5px;
    margin-bottom: 22px;
}

.alert-success {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
}

.stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.stat-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    box-shadow: var(--shadow);
}

.stat-label {
    font-size: 11.5px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    margin-bottom: 8px;
}

.stat-value {
    font-family: var(--mono);
    font-size: 30px;
    font-weight: 500;
    color: var(--navy);
    line-height: 1;
}

.stat-card.accent-teal   { border-top: 3px solid var(--teal); }
.stat-card.accent-amber  { border-top: 3px solid var(--amber); }
.stat-card.accent-green  { border-top: 3px solid var(--green); }
.stat-card.accent-navy   { border-top: 3px solid var(--navy); }


.charts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 18px;
    margin-bottom: 28px;
}

.chart-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
}

.card-heading {
    font-size: 13px;
    font-weight: 600;
    color: var(--ink);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.card-heading::before {
    content: '';
    width: 3px; height: 14px;
    background: var(--teal);
    border-radius: 2px;
    display: block;
}

/* ── Table section ───────────────────────────────────────────── */
.table-section {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 22px;
    border-bottom: 1px solid var(--border);
}

.table-wrapper {
    overflow-x: auto;
    max-height: 560px;
    overflow-y: auto;
}

table { width: 100%; border-collapse: collapse; }

thead th {
    background: #f8fafc;
    color: var(--muted);
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .07em;
    padding: 11px 16px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 1;
    white-space: nowrap;
}

tbody td {
    padding: 13px 16px;
    font-size: 13px;
    color: var(--ink);
    border-bottom: 1px solid #f0f3f7;
    vertical-align: middle;
}

tbody tr {
    border-left: 3px solid transparent;
    transition: background .1s;
}

tbody tr:hover { background: #f8fafc; }

/* Row accent strip by state */
tbody tr.row-pending   { border-left-color: var(--amber); }
tbody tr.row-completed { border-left-color: var(--green); }

.patient-name { font-weight: 500; color: var(--ink); }
.doctor-name  { font-size: 12px; color: var(--muted); margin-top: 1px; }

.mono-val {
    font-family: var(--mono);
    font-size: 13px;
    color: var(--navy);
    font-weight: 500;
}

/* ── Badges ──────────────────────────────────────────────────── */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.badge-pending   { background: #fef3c7; color: #92400e; }
.badge-completed { background: #d1fae5; color: #065f46; }
.badge-paid      { background: #dbeafe; color: #1e40af; }
.badge-unpaid    { background: #fef2f2; color: #991b1b; }

/* ── Buttons ─────────────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}

.btn-pay {
    background: var(--navy);
    color: #fff;
}
.btn-pay:hover { background: #0a2d55; transform: translateY(-1px); }

.btn-record {
    background: var(--teal);
    color: #fff;
}
.btn-record:hover { background: #0a6e60; transform: translateY(-1px); }

.btn-disabled {
    background: #f1f5f9;
    color: var(--muted);
    cursor: not-allowed;
    border: 1px solid var(--border);
}

/* ── Result form ─────────────────────────────────────────────── */
.result-form-section {
    display: none;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 24px;
    margin-top: 20px;
}

.result-form-section textarea {
    width: 100%;
    min-height: 120px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: var(--sans);
    font-size: 13.5px;
    color: var(--ink);
    resize: vertical;
    outline: none;
    margin-top: 12px;
    transition: border-color .15s;
}

.result-form-section textarea:focus { border-color: var(--teal); }

.form-actions { display: flex; gap: 10px; margin-top: 14px; }

.btn-save {
    background: var(--teal);
    color: #fff;
    padding: 9px 20px;
    font-size: 13px;
}
.btn-save:hover { background: #0a6e60; }

.btn-cancel-form {
    background: #f1f5f9;
    color: var(--muted);
    padding: 9px 16px;
    font-size: 13px;
    border: 1px solid var(--border);
}

/* ── Payment Modal ───────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(10, 20, 35, .5);
    z-index: 200;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
}

.modal-overlay.open { display: flex; }

.modal {
    background: var(--white);
    border-radius: 14px;
    width: 400px;
    max-width: 95vw;
    padding: 28px;
    box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: slideUp .2s ease;
}

@keyframes slideUp {
    from { transform: translateY(16px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

.modal-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 6px;
}

.modal-title { font-size: 16px; font-weight: 600; color: var(--ink); }

.modal-close {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--muted);
    font-size: 18px;
    line-height: 1;
    padding: 2px;
}
.modal-close:hover { color: var(--ink); }

.modal-test-name { font-size: 13px; color: var(--muted); margin-bottom: 14px; }

.modal-amount {
    background: var(--sky);
    border: 1px solid #c3d9f0;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 22px;
    display: flex;
    align-items: baseline;
    justify-content: space-between;
}

.modal-amount-label { font-size: 12px; color: var(--muted); font-weight: 500; }

.modal-amount-value {
    font-family: var(--mono);
    font-size: 26px;
    font-weight: 500;
    color: var(--navy);
}

.method-heading {
    font-size: 11.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--muted);
    margin-bottom: 10px;
}

.method-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 10px;
    margin-bottom: 16px;
}

.method-btn {
    border: 1.5px solid var(--border);
    background: var(--white);
    border-radius: 10px;
    padding: 13px 10px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 500;
    color: var(--muted);
    transition: all .15s;
}

.method-btn i { font-size: 20px; }
.method-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--sky); }
.method-btn.selected { border-color: var(--navy); color: var(--navy); background: var(--sky); font-weight: 600; }

.sub-options {
    display: none;
    gap: 10px;
    margin-bottom: 16px;
}

.sub-options.visible { display: flex; }

.sub-btn {
    flex: 1;
    border: 1.5px solid var(--border);
    background: var(--white);
    border-radius: 9px;
    padding: 10px;
    cursor: pointer;
    font-size: 12.5px;
    font-weight: 500;
    color: var(--muted);
    transition: all .15s;
    display: flex;
    align-items: center;
    gap: 7px;
    justify-content: center;
}

.sub-btn:hover  { border-color: var(--teal); color: var(--teal); }
.sub-btn.selected { border-color: var(--teal); color: var(--teal); background: #e6f7f5; font-weight: 600; }

.field-group { margin-bottom: 14px; }
.field-group label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 5px; }
.field-group input {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13.5px;
    font-family: var(--sans);
    color: var(--ink);
    outline: none;
    transition: border-color .15s;
    background: var(--white);
}
.field-group input:focus { border-color: var(--teal); }

.inline-fields { display: flex; gap: 10px; }
.inline-fields .field-group { flex: 1; }

.change-note {
    font-size: 12.5px;
    color: #065f46;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    border-radius: 7px;
    padding: 7px 12px;
    margin-bottom: 14px;
    display: none;
}

.modal-actions { display: flex; gap: 10px; margin-top: 6px; }

.btn-confirm {
    flex: 1;
    background: var(--teal);
    color: #fff;
    padding: 11px 20px;
    font-size: 13.5px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    transition: background .15s;
}
.btn-confirm:hover { background: #0a6e60; }

.btn-cancel-modal {
    padding: 11px 18px;
    font-size: 13.5px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: #f8fafc;
    color: var(--muted);
    cursor: pointer;
    font-weight: 500;
}
.btn-cancel-modal:hover { background: var(--border); }

/* Success screen */
.success-screen {
    display: none;
    text-align: center;
    padding: 10px 0;
}

.success-icon {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: #ecfdf5;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    font-size: 28px;
    color: var(--green);
}

.success-title { font-size: 18px; font-weight: 600; color: var(--ink); margin-bottom: 6px; }
.success-sub   { font-size: 13px; color: var(--muted); margin-bottom: 20px; }
.success-ref   {
    font-family: var(--mono);
    font-size: 11.5px;
    background: var(--bg);
    border: 1px dashed var(--border);
    border-radius: 7px;
    padding: 8px 14px;
    color: var(--muted);
    margin-bottom: 20px;
    letter-spacing: .05em;
}

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 860px) {
    .sidebar { display: none; }
    .main { padding: 16px; }
}

</style>
</head>
<body>

<!-- ═══ Topbar ══════════════════════════════════════════════════ -->
<header class="topbar">
    <div class="topbar-logo">
        <span class="pulse-dot"></span>
        MediPulse
    </div>
    <div class="topbar-right">
        <span>Lab Staff</span>
        <div class="topbar-avatar">
            <?= strtoupper(substr($_SESSION['full_name'], 0, 1)) ?>
        </div>
        <span style="color:#a8bcd4;"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
    </div>
</header>

<div class="shell">

    <nav class="sidebar">
        <a href="lab-dash.php" class="nav-item active">
            <i class="fas fa-th-large"></i> Dashboard
        </a>
        <a href="view_results.php" class="nav-item">
            <i class="fas fa-vials"></i> Lab Results
        </a>
        
        <a href="profile.php" class="nav-item">
            <i class="fas fa-user-circle"></i> Profile
        </a>
        <a href="login.php" class="nav-item danger">
            <i class="fas fa-sign-out-alt"></i> Log out
        </a>
    </nav>

    <main class="main">

        <div class="page-title">Lab Dashboard</div>
        <div class="page-sub">Overview of all test requests, results, and payments</div>

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-card accent-navy">
                <div class="stat-label">Total Tests</div>
                <div class="stat-value"><?= $totalTests ?></div>
            </div>
            <div class="stat-card accent-amber">
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= $pendingTests ?></div>
            </div>
            <div class="stat-card accent-green">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $completedTests ?></div>
            </div>
            <div class="stat-card accent-teal">
                <div class="stat-label">Paid</div>
                <div class="stat-value"><?= $paidTests ?></div>
            </div>
        </div>

        <div class="charts">
            <div class="chart-card">
                <div class="card-heading">Most Frequent Tests</div>
                <canvas id="testsChart" height="180"></canvas>
            </div>
            <div class="chart-card">
                <div class="card-heading">Tests Per Day</div>
                <canvas id="dailyChart" height="180"></canvas>
            </div>
        </div>

        <div class="table-section">
            <div class="table-header">
                <div class="card-heading" style="margin-bottom:0;">Lab Test Requests</div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Patient / Doctor</th>
                            <th>Test</th>
                            <th>Fee</th>
                            <th>Payment</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $requests->fetch_assoc()): ?>
                    <tr class="row-<?= strtolower($row['computed_status']) ?>">
                        <td>
                            <div class="patient-name"><?= htmlspecialchars($row['patient_name']) ?></div>
                            <div class="doctor-name"><?= htmlspecialchars($row['doctor_name']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($row['test_name']) ?></td>
                        <td>
                            <span class="mono-val">K <?= number_format($row['test_price'] ?? 0, 2) ?></span>
                        </td>
                        <td>
                            <?php if ($row['payment_status'] === 'Paid'): ?>
                                <span class="badge badge-paid"><i class="fas fa-check" style="font-size:9px;"></i> Paid</span>
                            <?php else: ?>
                                <span class="badge badge-unpaid">Unpaid</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;color:var(--muted);">
                            <?= date("d M Y", strtotime($row['requested_at'])) ?>
                            <div style="font-size:11px;"><?= date("H:i", strtotime($row['requested_at'])) ?></div>
                        </td>
                        <td>
                            <?php if ($row['computed_status'] === 'Completed'): ?>
                                <span class="badge badge-completed">Completed</span>
                            <?php else: ?>
                                <span class="badge badge-pending">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['payment_status'] !== 'Paid'): ?>
                                <button class="btn btn-pay open-pay-modal"
                                    data-id="<?= $row['lab_request_id'] ?>"
                                    data-test="<?= htmlspecialchars($row['test_name']) ?>"
                                    data-amount="<?= number_format($row['test_price'] ?? 0, 2) ?>">
                                    <i class="fas fa-credit-card"></i> Pay
                                </button>
                            <?php elseif ($row['computed_status'] === 'Pending'): ?>
                                <button class="btn btn-record recordBtn"
                                    data-id="<?= $row['lab_request_id'] ?>">
                                    <i class="fas fa-flask"></i> Record
                                </button>
                            <?php else: ?>
                                <span style="font-size:12px;color:var(--muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="result-form-section" id="resultFormSection">
            <div class="card-heading">Record Lab Result</div>
            <input type="hidden" id="lab_request_id">
            <textarea id="lab_result_text" placeholder="Enter the lab result findings here…"></textarea>
            <div class="form-actions">
                <button class="btn btn-save" id="submitResultBtn">
                    <i class="fas fa-save"></i> Save Result
                </button>
                <button class="btn btn-cancel-form" onclick="document.getElementById('resultFormSection').style.display='none'">
                    Cancel
                </button>
            </div>
        </div>

    </main>
</div>

<div class="modal-overlay" id="payModalOverlay">
    <div class="modal">

        <div id="payScreen">
            <div class="modal-top">
                <div class="modal-title">Process Payment</div>
                <button class="modal-close" onclick="closePayModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-test-name" id="modalTestName"></div>

            <div class="modal-amount">
                <span class="modal-amount-label">Amount due</span>
                <span class="modal-amount-value" id="modalAmountValue"></span>
            </div>

            <div class="method-heading">Payment method</div>
            <div class="method-grid">
                <button class="method-btn" data-method="cash" onclick="selectMethod('cash')">
                    <i class="fas fa-money-bill-wave"></i>
                    Cash
                </button>
                <button class="method-btn" data-method="mobile" onclick="selectMethod('mobile')">
                    <i class="fas fa-mobile-alt"></i>
                    Mobile Money
                </button>
                <button class="method-btn" data-method="card" onclick="selectMethod('card')">
                    <i class="fas fa-credit-card"></i>
                    Card
                </button>
            </div>

            <div class="sub-options" id="mobileSubOptions">
                <button class="sub-btn" data-sub="mtn" onclick="selectSub('mtn')">
                    <i class="fas fa-sim-card" style="color:#f59e0b;"></i> MTN Money
                </button>
                <button class="sub-btn" data-sub="airtel" onclick="selectSub('airtel')">
                    <i class="fas fa-sim-card" style="color:#ef4444;"></i> Airtel Money
                </button>
            </div>

            <div id="cashFields" style="display:none;">
                <div class="field-group">
                    <label>Amount tendered (K)</label>
                    <input type="number" id="cashTendered" placeholder="0.00" oninput="calcChange()">
                </div>
                <div class="change-note" id="changeNote">
                    <i class="fas fa-coins"></i>
                    Change: <strong id="changeAmt">K 0.00</strong>
                </div>
            </div>

            <div id="mobileFields" style="display:none;">
                <div class="field-group">
                    <label id="mobileLabel">Mobile number</label>
                    <input type="text" id="mobileNumber" placeholder="0961 234 567">
                </div>
            </div>

            <div id="cardFields" style="display:none;">
                <div class="field-group">
                    <label>Card number</label>
                    <input type="text" id="cardNumber" placeholder="•••• •••• •••• ••••" maxlength="19">
                </div>
                <div class="inline-fields">
                    <div class="field-group">
                        <label>Expiry</label>
                        <input type="text" id="cardExpiry" placeholder="MM / YY" maxlength="7">
                    </div>
                    <div class="field-group">
                        <label>CVV</label>
                        <input type="text" id="cardCvv" placeholder="•••" maxlength="3">
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn-cancel-modal" onclick="closePayModal()">Cancel</button>
                <button class="btn-confirm" onclick="confirmPayment()">
                    <i class="fas fa-lock" style="font-size:12px;margin-right:4px;"></i>
                    Confirm Payment
                </button>
            </div>
        </div>

        <div class="success-screen" id="successScreen">
            <div class="success-icon"><i class="fas fa-check"></i></div>
            <div class="success-title">Payment Successful</div>
            <div class="success-sub" id="successSub"></div>
            <div class="success-ref" id="successRef"></div>
            <form method="POST" id="markPaidForm">
                <input type="hidden" name="lab_request_id" id="markPaidId">
                <button type="submit" name="mark_paid" class="btn-confirm" style="width:100%;">
                    Done — Update Record
                </button>
            </form>
        </div>

    </div>
</div>

<script>

const chartDefaults = {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
        x: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 11 }, color: '#6b7b8d' } },
        y: { grid: { color: '#f0f3f7' }, ticks: { font: { family: 'JetBrains Mono', size: 11 }, color: '#6b7b8d', precision: 0 } }
    }
};

new Chart(document.getElementById('testsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($testLabels) ?>,
        datasets: [{
            data: <?= json_encode($testData) ?>,
            backgroundColor: '#0d3b6e',
            borderRadius: 5,
            borderSkipped: false
        }]
    },
    options: chartDefaults
});

new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($dayLabels) ?>,
        datasets: [{
            data: <?= json_encode($dayData) ?>,
            borderColor: '#0e8a7a',
            backgroundColor: 'rgba(14,138,122,.08)',
            borderWidth: 2,
            tension: .35,
            fill: true,
            pointBackgroundColor: '#0e8a7a',
            pointRadius: 4
        }]
    },
    options: chartDefaults
});

document.querySelectorAll('.recordBtn').forEach(btn => {
    btn.onclick = () => {
        document.getElementById('lab_request_id').value = btn.dataset.id;
        const section = document.getElementById('resultFormSection');
        section.style.display = 'block';
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
});

document.getElementById('submitResultBtn').onclick = () => {
    const id     = document.getElementById('lab_request_id').value;
    const result = document.getElementById('lab_result_text').value;
    if (!result.trim()) { alert('Please enter a lab result.'); return; }
    fetch('save-lab-result.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `lab_request_id=${id}&result=${encodeURIComponent(result)}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { alert('Result saved successfully.'); location.reload(); }
        else           { alert('Failed to save result.'); }
    });
};

let _amount = 0, _method = null, _sub = null, _reqId = null;

document.querySelectorAll('.open-pay-modal').forEach(btn => {
    btn.onclick = () => {
        _reqId  = btn.dataset.id;
        _amount = parseFloat(btn.dataset.amount.replace(/,/g,''));
        _method = null; _sub = null;

        document.getElementById('modalTestName').textContent  = btn.dataset.test;
        document.getElementById('modalAmountValue').textContent = 'K ' + _amount.toFixed(2);
        document.getElementById('markPaidId').value = _reqId;

        document.querySelectorAll('.method-btn').forEach(b => b.classList.remove('selected'));
        document.querySelectorAll('.sub-btn').forEach(b => b.classList.remove('selected'));
        document.getElementById('mobileSubOptions').classList.remove('visible');
        ['cashFields','mobileFields','cardFields'].forEach(f => document.getElementById(f).style.display = 'none');
        document.getElementById('changeNote').style.display = 'none';
        document.getElementById('cashTendered').value = '';
        document.getElementById('mobileNumber').value = '';
        document.getElementById('cardNumber').value   = '';
        document.getElementById('cardExpiry').value   = '';
        document.getElementById('cardCvv').value      = '';

        document.getElementById('payScreen').style.display    = 'block';
        document.getElementById('successScreen').style.display = 'none';
        document.getElementById('payModalOverlay').classList.add('open');
    };
});

function closePayModal() {
    document.getElementById('payModalOverlay').classList.remove('open');
}

document.getElementById('payModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});

function selectMethod(m) {
    _method = m; _sub = null;
    document.querySelectorAll('.method-btn').forEach(b => b.classList.toggle('selected', b.dataset.method === m));
    document.getElementById('mobileSubOptions').classList.toggle('visible', m === 'mobile');
    document.getElementById('cashFields').style.display   = m === 'cash'   ? 'block' : 'none';
    document.getElementById('mobileFields').style.display = m === 'mobile' ? 'block' : 'none';
    document.getElementById('cardFields').style.display   = m === 'card'   ? 'block' : 'none';
    document.querySelectorAll('.sub-btn').forEach(b => b.classList.remove('selected'));
    if (m !== 'cash') document.getElementById('changeNote').style.display = 'none';
}

function selectSub(s) {
    _sub = s;
    document.querySelectorAll('.sub-btn').forEach(b => b.classList.toggle('selected', b.dataset.sub === s));
    document.getElementById('mobileLabel').textContent = s === 'mtn' ? 'MTN Money number' : 'Airtel Money number';
}

function calcChange() {
    const tendered = parseFloat(document.getElementById('cashTendered').value) || 0;
    const change   = tendered - _amount;
    const note     = document.getElementById('changeNote');
    note.style.display = tendered > 0 ? 'block' : 'none';
    document.getElementById('changeAmt').textContent = 'K ' + Math.max(0, change).toFixed(2);
}

function confirmPayment() {
    if (!_method) { alert('Please select a payment method.'); return; }
    if (_method === 'mobile' && !_sub) { alert('Please select MTN or Airtel.'); return; }

    const label = _method === 'cash'   ? 'Cash'
                : _method === 'card'   ? 'Card'
                : _sub === 'mtn'       ? 'MTN Mobile Money' : 'Airtel Mobile Money';

    const ref = 'TXN-' + Date.now().toString(36).toUpperCase();

    document.getElementById('successSub').textContent =
        `K ${_amount.toFixed(2)} received via ${label}.`;
    document.getElementById('successRef').textContent = 'Reference: ' + ref;

    document.getElementById('payScreen').style.display    = 'none';
    document.getElementById('successScreen').style.display = 'block';
}

document.getElementById('cardNumber').addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0,16);
    this.value = v.replace(/(.{4})/g,'$1 ').trim();
});

document.getElementById('cardExpiry').addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0,4);
    if (v.length >= 2) v = v.substring(0,2) + ' / ' + v.substring(2);
    this.value = v;
});

</script>
</body>
</html>