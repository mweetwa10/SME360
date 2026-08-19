<?php
session_start();
include 'db.php';

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    echo "Access denied.";
    exit();
}

date_default_timezone_set("Africa/Lusaka");

$report  = $_GET['report']  ?? '';
$period  = $_GET['period']  ?? '';

$title       = "";
$resultData  = [];
$filterLabel = "";
$headers     = ["Description", "Value"];

function getDateCondition($period, $column) {

    global $filterLabel;

    
    if ($period === 'daily') {
        $date = $_GET['date'] ?? '';
        if (empty($date)) return "1=0";
        
        if (substr($date, 0, 4) !== '2026' || $date > date('Y-m-d')) return "1=0";
        $filterLabel = date("d F Y", strtotime($date));
        $safe = mysqli_real_escape_string($GLOBALS['conn'], $date);
        return "DATE($column) = '$safe'";
    }

    
    if ($period === 'monthly') {
        $month = intval($_GET['month']      ?? 0);
        $year  = intval($_GET['month_year'] ?? 0);
        if (!$month || $year !== 2026) return "1=0";
    
        if ($month > intval(date('n'))) return "1=0";
        $filterLabel = date("F Y", strtotime("$year-" . str_pad($month,2,'0',STR_PAD_LEFT) . "-01"));
        return "MONTH($column) = $month AND YEAR($column) = $year";
    }

    if ($period === 'yearly') {
        $year = intval($_GET['year'] ?? 0);
        if ($year !== 2026) return "1=0";
        $filterLabel = $year;
        return "YEAR($column) = $year";
    }

    return "1=1";
}


if ($report) {

    switch ($report) {

        case 'patients_total':
            $row           = $conn->query("SELECT COUNT(*) AS total FROM patients")->fetch_assoc();
            $resultData[]  = ["Total Patients", $row['total']];
            $title         = "Total Patients";
            $filterLabel   = "All Time";
            break;

        case 'patients_registered':
            $cond          = getDateCondition($period, "created_at");
            $row           = $conn->query("SELECT COUNT(*) AS total FROM patients WHERE $cond")->fetch_assoc();
            $resultData[]  = ["Patients Registered", $row['total']];
            $title         = "Patients Registered";
            break;

        case 'patients_attended':
            $cond          = getDateCondition($period, "consultation_date");
            $row           = $conn->query("SELECT COUNT(*) AS total FROM consultations WHERE $cond")->fetch_assoc();
            $resultData[]  = ["Patients Attended", $row['total']];
            $title         = "Patients Attended";
            break;

        case 'patients_per_doctor':
            $cond     = getDateCondition($period, "c.consultation_date");
            $res      = $conn->query("
                SELECT d.full_name, COUNT(c.consultation_id) AS total
                FROM consultations c
                INNER JOIN doctors d ON c.doctor_id = d.doctor_id
                WHERE $cond
                GROUP BY d.doctor_id
                ORDER BY total DESC
            ");
            $headers  = ["Doctor", "Patients Seen"];
            while ($row = $res->fetch_assoc()) {
                $resultData[] = [$row['full_name'], $row['total']];
            }
            $title = "Patients Per Doctor";
            break;

        case 'staff_total':
    
            $tables = ["doctors","nurses","pharmacists","lab_staff","receptionists"];
            $labels = ["Doctors","Nurses","Pharmacists","Lab Staff","Receptionists"];
            $headers = ["Staff Category", "Current Count"];
            $grandTotal = 0;
            foreach ($tables as $i => $tbl) {
                $row = $conn->query("SELECT COUNT(*) AS total FROM $tbl")->fetch_assoc();
                $resultData[] = [$labels[$i], $row['total']];
                $grandTotal  += $row['total'];
            }
            $resultData[] = ["TOTAL", $grandTotal];
            $title        = "Total Staff (Current Headcount)";
            $filterLabel  = "All Time — shows current registered staff regardless of period";
            break;

        case 'staff_new':
            $tables  = ["doctors","nurses","pharmacists","lab_staff","receptionists"];
            $labels  = ["Doctors","Nurses","Pharmacists","Lab Staff","Receptionists"];
            $headers = ["Staff Category", "New Hires"];
            $grandTotal = 0;
            foreach ($tables as $i => $tbl) {
                $cond = getDateCondition($period, "created_at");
                $row  = $conn->query("SELECT COUNT(*) AS total FROM $tbl WHERE $cond")->fetch_assoc();
                $resultData[] = [$labels[$i], $row['total']];
                $grandTotal  += $row['total'];
            }
            $resultData[] = ["TOTAL", $grandTotal];
            $title        = "New Staff";
            break;

        case 'appointments':
            $cond         = getDateCondition($period, "preferred_date");
            $row          = $conn->query("SELECT COUNT(*) AS total FROM appointments WHERE $cond")->fetch_assoc();
            $resultData[] = ["Appointments", $row['total']];
            $title        = "Appointments";
            break;

        case 'lab_tests':
            $cond         = getDateCondition($period, "requested_at");
            $row          = $conn->query("SELECT COUNT(*) AS total FROM lab_requests WHERE $cond")->fetch_assoc();
            $resultData[] = ["Lab Tests Conducted", $row['total']];
            $title        = "Laboratory Tests";
            break;

        case 'top_tests':
            $cond    = getDateCondition($period, "requested_at");
            $res     = $conn->query("
                SELECT test_name, COUNT(*) AS total
                FROM lab_requests
                WHERE $cond
                GROUP BY test_name
                ORDER BY total DESC
                LIMIT 10
            ");
            $headers = ["Test Name", "Requests"];
            while ($row = $res->fetch_assoc()) {
                $resultData[] = [$row['test_name'], $row['total']];
            }
            $title = "Most Requested Tests";
            break;

        case 'top_medicine':
            $cond    = getDateCondition($period, "p.created_at");
            $res     = $conn->query("
                SELECT m.name, COUNT(p.prescription_id) AS total
                FROM prescriptions p
                INNER JOIN medicines m ON p.medicine_id = m.medicine_id
                WHERE $cond
                GROUP BY m.medicine_id
                ORDER BY total DESC
                LIMIT 10
            ");
            $headers = ["Medicine", "Times Prescribed"];
            while ($row = $res->fetch_assoc()) {
                $resultData[] = [$row['name'], $row['total']];
            }
            $title = "Most Used Medicines";
            break;

        case 'medicine_stock':
            $res     = $conn->query("SELECT name, stock_quantity FROM medicines ORDER BY stock_quantity ASC");
            $headers = ["Medicine", "Stock Qty"];
            while ($row = $res->fetch_assoc()) {
                $resultData[] = [$row['name'], $row['stock_quantity']];
            }
            $title       = "Medicine Stock Levels";
            $filterLabel = "Current Stock";
            break;

        case 'medicine_expiry':
            $res     = $conn->query("SELECT name, expiry_date FROM medicines ORDER BY expiry_date ASC");
            $headers = ["Medicine", "Expiry Date"];
            while ($row = $res->fetch_assoc()) {
                $resultData[] = [$row['name'], date("d M Y", strtotime($row['expiry_date']))];
            }
            $title       = "Medicine Expiry Dates";
            $filterLabel = "All Medicines";
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediPulse | Reports</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:"Segoe UI",Tahoma,Verdana,sans-serif; }
body { background:#f4f7fb; color:#333; }

header {
    background:#0f4c81; color:#fff;
    padding:18px 30px;
    display:flex; justify-content:space-between; align-items:center;
}

.container { display:flex; min-height:calc(100vh - 75px); }

.sidebar { width:250px; background:#fff; padding:20px; box-shadow:2px 0 10px rgba(0,0,0,.05); }
.sidebar ul { list-style:none; }
.sidebar li { margin-bottom:10px; }
.sidebar li a { text-decoration:none; color:#333; display:block; padding:12px; border-radius:8px; }
.sidebar li a:hover { background:#f0f6ff; }
.sidebar i { margin-right:10px; }

.main { flex:1; padding:30px; }

.card {
    background:#fff; padding:25px;
    border-radius:14px; margin-bottom:25px;
    box-shadow:0 8px 20px rgba(0,0,0,.05);
}
.card h3 { margin-bottom:20px; color:#0f4c81; }

label { display:block; margin-top:15px; margin-bottom:8px; font-weight:600; }
input, select {
    width:100%; padding:12px;
    border:1px solid #ccc; border-radius:8px; font-size:14px;
}

.btn-row { display:flex; gap:12px; margin-top:20px; flex-wrap:wrap; }

.btn {
    padding:12px 20px; background:#0f4c81;
    color:#fff; border:none; border-radius:8px;
    cursor:pointer; font-size:14px; display:flex; align-items:center; gap:8px;
}
.btn:hover { background:#0c3c66; }
.btn-print { background:#28a745; }
.btn-print:hover { background:#218838; }

.report-table { width:100%; border-collapse:collapse; }
.report-table th { background:#0f4c81; color:#fff; }
.report-table th, .report-table td {
    padding:13px 16px; border-bottom:1px solid #eee; text-align:left;
}
.report-table tr:last-child td { font-weight:700; background:#f0f6ff; }
.report-table tr:hover td { background:#f9fbff; }

.badge {
    background:#eef5ff; color:#0f4c81;
    padding:5px 12px; border-radius:30px; font-weight:700;
}

.period-box { display:none; }

@media print {
    header, .sidebar, .card:first-of-type, .btn-row { display:none !important; }
    body { background:#fff; }
    .container { display:block; }
    .main { padding:0; }
    .card { box-shadow:none; border:none; }

    .print-header {
        display:block !important;
        text-align:center;
        margin-bottom:20px;
        border-bottom:2px solid #0f4c81;
        padding-bottom:12px;
    }
    .report-table th { background:#0f4c81 !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .badge { background:none; padding:0; font-weight:700; }
}

.print-header { display:none; }
</style>
</head>
<body>

<header>
    <h2>MediPulse Reports</h2>
    <div>Logged in as: <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong></div>
</header>

<div class="container">

    <div class="sidebar">
        <ul>
            <li><a href="admin-dash.php"><i class="fa-solid fa-gauge"></i>Dashboard</a></li>
            <li><a href="reports.php"><i class="fa-solid fa-chart-line"></i>Reports</a></li>
        </ul>
    </div>

    <div class="main">

        <div class="card">
            <h3><i class="fa-solid fa-filter" style="margin-right:8px;"></i>Generate Report</h3>

            <form method="GET">

                <label>Report Type</label>
                <select name="report" required>
                    <option value="">-- Select Report --</option>
                    <optgroup label="Patients">
                        <option value="patients_total"   <?= $report=='patients_total'   ?'selected':'' ?>>Total Patients</option>
                        <option value="patients_registered" <?= $report=='patients_registered'?'selected':'' ?>>Patients Registered</option>
                        <option value="patients_attended"   <?= $report=='patients_attended'  ?'selected':'' ?>>Patients Attended</option>
                        <option value="patients_per_doctor" <?= $report=='patients_per_doctor'?'selected':'' ?>>Patients Per Doctor</option>
                    </optgroup>
                    <optgroup label="Staff">
                        <option value="staff_total" <?= $report=='staff_total'?'selected':'' ?>>Total Staff</option>
                        <option value="staff_new"   <?= $report=='staff_new'  ?'selected':'' ?>>New Staff</option>
                    </optgroup>
                    <optgroup label="Appointments">
                        <option value="appointments" <?= $report=='appointments'?'selected':'' ?>>Appointments</option>
                    </optgroup>
                    <optgroup label="Laboratory">
                        <option value="lab_tests"  <?= $report=='lab_tests' ?'selected':'' ?>>Lab Tests</option>
                        <option value="top_tests"  <?= $report=='top_tests' ?'selected':'' ?>>Most Requested Tests</option>
                    </optgroup>
                    <optgroup label="Pharmacy">
                        <option value="top_medicine"    <?= $report=='top_medicine'   ?'selected':'' ?>>Most Used Medicines</option>
                        <option value="medicine_stock"  <?= $report=='medicine_stock' ?'selected':'' ?>>Medicine Stock Levels</option>
                        <option value="medicine_expiry" <?= $report=='medicine_expiry'?'selected':'' ?>>Medicine Expiry Dates</option>
                    </optgroup>
                </select>

                <label>Period</label>
                <select name="period" id="period" onchange="toggleInputs()" required>
                    <option value="">-- Select Period --</option>
                    <option value="daily"   <?= $period=='daily'  ?'selected':'' ?>>Daily</option>
                    <option value="monthly" <?= $period=='monthly'?'selected':'' ?>>Monthly</option>
                    <option value="yearly"  <?= $period=='yearly' ?'selected':'' ?>>Yearly</option>
                </select>

                <div id="dailyBox" class="period-box">
                    <label>Select Date</label>
                    <input type="date" name="date" id="dateInput"
                           min="2026-01-01"
                           max="<?= date('Y-m-d') ?>"
                           value="<?= htmlspecialchars($_GET['date'] ?? '') ?>">
                </div>

                <div id="monthlyBox" class="period-box">
                    <label>Select Year</label>
                    <select name="month_year" id="monthYear" onchange="filterMonths()">
                        <option value="2026" selected>2026</option>
                    </select>
                    <label>Select Month</label>
                    <select name="month" id="monthSelect">
                        <?php
                        $monthNames = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April',
                                       '05'=>'May','06'=>'June','07'=>'July','08'=>'August',
                                       '09'=>'September','10'=>'October','11'=>'November','12'=>'December'];
                        foreach ($monthNames as $val => $name):
                            $sel = (($_GET['month'] ?? '') === $val) ? 'selected' : '';
                        ?>
                            <option value="<?= $val ?>" <?= $sel ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="yearlyBox" class="period-box">
                    <label>Select Year</label>
                    <select name="year">
                        <option value="2026" selected>2026</option>
                    </select>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn">
                        <i class="fa-solid fa-magnifying-glass"></i> Generate Report
                    </button>
                </div>

            </form>
        </div>

        <?php if (!empty($resultData)): ?>
        <div class="card" id="reportResult">

            <div class="print-header">
                <h2 style="color:#0f4c81;">MediPulse Hospital</h2>
                <p style="color:#555;margin-top:4px;">Generated on: <?= date("d F Y, H:i") ?></p>
            </div>

            <h3><?= htmlspecialchars($title) ?></h3>
            <?php if ($filterLabel): ?>
                <p style="margin-bottom:16px;color:#666;font-size:14px;">
                    <i class="fa-solid fa-calendar-days"></i>
                    &nbsp;<?= htmlspecialchars($filterLabel) ?>
                </p>
            <?php endif; ?>

            <div class="btn-row">
                <button class="btn btn-print" onclick="window.print()">
                    <i class="fa-solid fa-print"></i> Print Report
                </button>
            </div>

            <br>

            <table class="report-table">
                <tr>
                    <th><?= htmlspecialchars($headers[0]) ?></th>
                    <th><?= htmlspecialchars($headers[1]) ?></th>
                </tr>
                <?php foreach ($resultData as $i => $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row[0]) ?></td>
                    <td><span class="badge"><?= htmlspecialchars($row[1]) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </table>

        </div>
        <?php endif; ?>

    </div>
</div>

<script>
const TODAY      = new Date();
const THIS_YEAR  = TODAY.getFullYear();
const THIS_MONTH = TODAY.getMonth() + 1; 

const pad  = n => String(n).padStart(2, '0');
const todayStr = `${THIS_YEAR}-${pad(THIS_MONTH)}-${pad(TODAY.getDate())}`;

function initDailyPicker() {
    const inp = document.getElementById('dateInput');
    if (!inp) return;
    inp.min = '2026-01-01';
    inp.max = todayStr;

    function clamp() {
        if (this.value < '2026-01-01') this.value = '2026-01-01';
        if (this.value > todayStr)     this.value = todayStr;
    }

    inp.addEventListener('change', clamp);
    inp.addEventListener('keyup',  clamp);
}

function filterMonths() {
    const monthSel = document.getElementById('monthSelect');
    if (!monthSel) return;

    Array.from(monthSel.options).forEach(opt => {
        const monthNum = parseInt(opt.value);
        if (monthNum > THIS_MONTH) {
            opt.disabled = true;
            opt.style.color = '#bbb';
            if (monthSel.value === opt.value) {
                monthSel.value = pad(THIS_MONTH);
            }
        } else {
            opt.disabled = false;
            opt.style.color = '';
        }
    });
}

function toggleInputs() {
    ['dailyBox','monthlyBox','yearlyBox'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    const p = document.getElementById('period').value;
    if (p === 'daily')   document.getElementById('dailyBox').style.display   = 'block';
    if (p === 'monthly') document.getElementById('monthlyBox').style.display = 'block';
    if (p === 'yearly')  document.getElementById('yearlyBox').style.display  = 'block';
}

(function(){
    initDailyPicker();
    filterMonths(); 
    const p = document.getElementById('period').value;
    if (p) toggleInputs();
})();
</script>

</body>
</html>