<?php
require_once "auth_check.php";
require_once "db.php";

$business_id = $_SESSION["business_id"] ?? "";

if ($business_id === "") {
    header("Location: login.php");
    exit;
}

/**
 * Small helpers so every query below is a single call instead of
 * prepare/execute/fetch repeated by hand each time.
 */
function fetchRow($pdo, $sql, $params)
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function fetchRows($pdo, $sql, $params)
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$business = fetchRow($pdo, "
    SELECT business_name, phone, email, address
    FROM businesses
    WHERE business_id = :business_id
    LIMIT 1
", [":business_id" => $business_id]);

if (!$business) {
    die("Business information could not be found.");
}

$full_name = $_SESSION["full_name"] ?? "User";
$role_name = $_SESSION["role_name"] ?? "Staff";

$report_type = $_GET["report_type"] ?? "daily";
$today = date("Y-m-d");

$start_date = $today;
$end_date = $today;

if ($report_type === "monthly") {

    $start_date = date("Y-m-01");
    $end_date = date("Y-m-t");

} elseif ($report_type === "yearly") {

    $start_date = date("Y-01-01");
    $end_date = date("Y-12-31");

} elseif ($report_type === "custom") {

    $start_date = $_GET["start_date"] ?? $today;
    $end_date = $_GET["end_date"] ?? $today;

    if ($start_date === "") {
        $start_date = $today;
    }

    if ($end_date === "") {
        $end_date = $today;
    }

    // Never allow a future date to be queried.
    if ($start_date > $today) {
        $start_date = $today;
    }

    if ($end_date > $today) {
        $end_date = $today;
    }

    if ($start_date > $end_date) {
        [$start_date, $end_date] = [$end_date, $start_date];
    }

}

$reportTitles = [
    "daily" => "Daily Report",
    "monthly" => "Monthly Report",
    "yearly" => "Yearly Report",
    "custom" => "Custom Date Range Report"
];

$reportTitle = $reportTitles[$report_type] ?? "Daily Report";

function formatMoney($amount)
{
    return "K " . number_format((float) $amount, 2);
}

function formatDateDisplay($date)
{
    return date("d M Y", strtotime($date));
}

$dateParams = [
    ":business_id" => $business_id,
    ":start_date" => $start_date,
    ":end_date" => $end_date
];

$salesSummary = fetchRow($pdo, "
    SELECT
        COUNT(*) AS sales_count,
        COALESCE(SUM(total_amount), 0) AS total_sales,
        COALESCE(SUM(amount_paid), 0) AS amount_received,
        COALESCE(SUM(balance), 0) AS outstanding_credit,
        COALESCE(SUM(discount), 0) AS total_discount,
        COALESCE(SUM(tax), 0) AS total_tax
    FROM sales
    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND DATE(sale_date) BETWEEN :start_date AND :end_date
", $dateParams);

$salesCount = (int) ($salesSummary["sales_count"] ?? 0);
$totalSales = (float) ($salesSummary["total_sales"] ?? 0);
$amountReceived = (float) ($salesSummary["amount_received"] ?? 0);
$outstandingCredit = (float) ($salesSummary["outstanding_credit"] ?? 0);
$totalDiscount = (float) ($salesSummary["total_discount"] ?? 0);
$totalTax = (float) ($salesSummary["total_tax"] ?? 0);

$profitData = fetchRow($pdo, "
    SELECT
        COALESCE(SUM((si.unit_price - p.cost_price) * si.quantity), 0) AS gross_profit,
        COALESCE(SUM(si.quantity), 0) AS items_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.sale_id
    INNER JOIN products p ON si.product_id = p.product_id AND p.business_id = s.business_id
    WHERE s.business_id = :business_id
    AND s.sale_status = 'completed'
    AND DATE(s.sale_date) BETWEEN :start_date AND :end_date
", $dateParams);

$grossProfit = (float) ($profitData["gross_profit"] ?? 0);
$itemsSold = (float) ($profitData["items_sold"] ?? 0);

$expenseSummary = fetchRow($pdo, "
    SELECT
        COUNT(*) AS expense_count,
        COALESCE(SUM(amount), 0) AS total_expenses
    FROM expenses
    WHERE business_id = :business_id
    AND status = 'active'
    AND expense_date BETWEEN :start_date AND :end_date
", $dateParams);

$expenseCount = (int) ($expenseSummary["expense_count"] ?? 0);
$totalExpenses = (float) ($expenseSummary["total_expenses"] ?? 0);

$netProfit = $grossProfit - $totalExpenses;

$paymentStatuses = fetchRows($pdo, "
    SELECT
        payment_status,
        COUNT(*) AS sale_count,
        COALESCE(SUM(total_amount), 0) AS sale_value,
        COALESCE(SUM(amount_paid), 0) AS amount_paid,
        COALESCE(SUM(balance), 0) AS balance
    FROM sales
    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND DATE(sale_date) BETWEEN :start_date AND :end_date
    GROUP BY payment_status
    ORDER BY payment_status
", $dateParams);

$paymentMethods = fetchRows($pdo, "
    SELECT
        payment_method,
        COUNT(*) AS sale_count,
        COALESCE(SUM(total_amount), 0) AS total_value,
        COALESCE(SUM(amount_paid), 0) AS amount_received
    FROM sales
    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND DATE(sale_date) BETWEEN :start_date AND :end_date
    GROUP BY payment_method
    ORDER BY amount_received DESC
", $dateParams);

$expenseCategories = fetchRows($pdo, "
    SELECT
        ec.category_name,
        COUNT(e.expense_id) AS expense_count,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM expenses e
    INNER JOIN expense_categories ec ON e.expense_category_id = ec.expense_category_id
    WHERE e.business_id = :business_id
    AND e.status = 'active'
    AND e.expense_date BETWEEN :start_date AND :end_date
    GROUP BY ec.expense_category_id, ec.category_name
    ORDER BY total_amount DESC
", $dateParams);

$topProducts = fetchRows($pdo, "
    SELECT
        p.product_name,
        p.product_type,
        COALESCE(SUM(si.quantity), 0) AS quantity_sold,
        COALESCE(SUM(si.unit_price * si.quantity), 0) AS sales_value,
        COALESCE(SUM((si.unit_price - p.cost_price) * si.quantity), 0) AS profit
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.sale_id
    INNER JOIN products p ON si.product_id = p.product_id AND p.business_id = s.business_id
    WHERE s.business_id = :business_id
    AND s.sale_status = 'completed'
    AND DATE(s.sale_date) BETWEEN :start_date AND :end_date
    GROUP BY p.product_id, p.product_name, p.product_type
    ORDER BY quantity_sold DESC
    LIMIT 10
", $dateParams);

$salesDetails = fetchRows($pdo, "
    SELECT
        sale_id, sale_date, customer_name, customer_phone,
        total_amount, amount_paid, balance, payment_method, payment_status
    FROM sales
    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND DATE(sale_date) BETWEEN :start_date AND :end_date
    ORDER BY sale_date DESC
", $dateParams);

$expenseDetails = fetchRows($pdo, "
    SELECT
        e.expense_id, e.expense_date, ec.category_name,
        e.description, e.amount, e.payment_method
    FROM expenses e
    INNER JOIN expense_categories ec ON e.expense_category_id = ec.expense_category_id
    WHERE e.business_id = :business_id
    AND e.status = 'active'
    AND e.expense_date BETWEEN :start_date AND :end_date
    ORDER BY e.expense_date DESC
", $dateParams);

$invoiceCredit = fetchRow($pdo, "
    SELECT
        COUNT(*) AS invoice_count,
        COALESCE(SUM(balance), 0) AS outstanding_invoices
    FROM invoices
    WHERE business_id = :business_id
    AND status IN ('issued', 'partially_paid', 'overdue')
", [":business_id" => $business_id]);

$invoiceCount = (int) ($invoiceCredit["invoice_count"] ?? 0);
$outstandingInvoices = (float) ($invoiceCredit["outstanding_invoices"] ?? 0);

$invoicePaymentData = fetchRow($pdo, "
    SELECT
        COUNT(*) AS payment_count,
        COALESCE(SUM(amount), 0) AS invoice_payments
    FROM payments
    WHERE business_id = :business_id
    AND DATE(payment_date) BETWEEN :start_date AND :end_date
", $dateParams);

$invoicePaymentCount = (int) ($invoicePaymentData["payment_count"] ?? 0);
$invoicePayments = (float) ($invoicePaymentData["invoice_payments"] ?? 0);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reports | SME360</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #f4f7fb;
    color: #1f2937;
}

.sidebar {
    width: 250px;
    background: #111827;
    color: white;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    padding: 25px 18px;
    overflow-y: auto;
}

.logo {
    font-size: 27px;
    font-weight: 700;
    letter-spacing: 1px;
    padding: 0 15px 30px;
}

.logo span {
    color: #22c55e;
}

.nav-title {
    color: #6b7280;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 0 15px;
    margin: 20px 0 10px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 15px;
    color: #d1d5db;
    text-decoration: none;
    border-radius: 8px;
    margin-bottom: 5px;
    font-size: 14px;
}

.nav-link:hover,
.nav-link.active {
    background: #1f2937;
    color: #22c55e;
}

.logout {
    margin-top: 30px;
    color: #f87171;
}

.main {
    margin-left: 250px;
    min-height: 100vh;
}

.topbar {
    height: 75px;
    background: white;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 35px;
}

.topbar h1 {
    font-size: 22px;
}

.user {
    font-size: 14px;
    color: #6b7280;
}

.user strong {
    color: #111827;
}

.content {
    padding: 30px 35px;
}

.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 25px;
}

.page-header h2 {
    font-size: 20px;
}

.page-header p {
    color: #6b7280;
    font-size: 13px;
    margin-top: 5px;
}

.print-button {
    background: #111827;
    color: white;
    border: none;
    padding: 11px 18px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
}

.filter-card {
    background: white;
    padding: 20px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    margin-bottom: 25px;
}

.filter-form {
    display: flex;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 600;
}

.filter-group select,
.filter-group input {
    padding: 11px 12px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    min-width: 170px;
}

.filter-button {
    background: #22c55e;
    color: white;
    border: none;
    padding: 11px 20px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
}

.custom-dates {
    display: none;
    gap: 12px;
}

.custom-dates.show {
    display: flex;
}

.report-period {
    background: #111827;
    color: white;
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 25px;
}

.report-period h3 {
    font-size: 18px;
    margin-bottom: 5px;
}

.report-period p {
    color: #d1d5db;
    font-size: 13px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 22px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
}

.stat-label {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 10px;
}

.stat-value {
    font-size: 23px;
    font-weight: 700;
    color: #111827;
}

.stat-description {
    color: #9ca3af;
    font-size: 11px;
    margin-top: 8px;
}

.green {
    color: #16a34a !important;
}

.red {
    color: #dc2626 !important;
}

.orange {
    color: #ea580c !important;
}

.blue {
    color: #2563eb !important;
}

.section-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
    margin-bottom: 25px;
}

.card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 25px;
}

.card-header {
    padding: 18px 22px;
    border-bottom: 1px solid #e5e7eb;
}

.card-header h3 {
    font-size: 16px;
}

.card-header p {
    color: #9ca3af;
    font-size: 12px;
    margin-top: 5px;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    background: #f9fafb;
    text-align: left;
    padding: 13px 18px;
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
    white-space: nowrap;
}

td {
    padding: 13px 18px;
    border-top: 1px solid #f3f4f6;
    font-size: 13px;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 600;
}

.status-paid {
    background: #dcfce7;
    color: #15803d;
}

.status-partial {
    background: #fef3c7;
    color: #b45309;
}

.status-unpaid {
    background: #fee2e2;
    color: #b91c1c;
}

.empty {
    text-align: center;
    padding: 35px;
    color: #9ca3af;
}

@media (max-width: 1100px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .section-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 800px) {
    .sidebar {
        width: 70px;
        padding: 20px 10px;
    }

    .logo {
        font-size: 0;
        text-align: center;
    }

    .logo::before {
        content: "S";
        font-size: 25px;
        color: #22c55e;
    }

    .nav-link {
        justify-content: center;
    }

    .nav-link span:last-child,
    .nav-title {
        display: none;
    }

    .main {
        margin-left: 70px;
    }

    .content {
        padding: 20px;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .custom-dates {
        flex-direction: column;
        width: 100%;
    }

    .filter-group {
        width: 100%;
    }

    .filter-group select,
    .filter-group input {
        width: 100%;
    }
}

@media print {

    @page {
        size: A4;
        margin: 15mm;
    }

    body {
        background: white;
    }

    .sidebar,
    .topbar,
    .filter-card,
    .print-button {
        display: none !important;
    }

    .main {
        margin-left: 0;
        width: 100%;
    }

    .content {
        padding: 0;
    }

    .report-period {
        background: white;
        color: black;
        border: 1px solid #ddd;
    }

    .report-period p {
        color: #333;
    }

    .card,
    .stat-card {
        break-inside: avoid;
        border: 1px solid #ddd;
    }

    .section-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
    }
}
</style>

<script>
function toggleCustomDates() {
    const reportType = document.getElementById("report_type").value;
    const customDates = document.getElementById("customDates");

    if (reportType === "custom") {
        customDates.classList.add("show");
        updateDateConstraints();
    } else {
        customDates.classList.remove("show");
    }
}

// Keeps the From/To date pickers restricted to a valid range:
// - neither field can be set to a future date
// - "To" can never be earlier than "From"
// - "From" can never be later than "To"
function updateDateConstraints() {
    const today = new Date().toISOString().split("T")[0];
    const startInput = document.getElementById("start_date");
    const endInput = document.getElementById("end_date");

    startInput.max = endInput.value || today;
    endInput.min = startInput.value || "";
    endInput.max = today;
}

window.addEventListener("DOMContentLoaded", function() {
    toggleCustomDates();
    updateDateConstraints();
});
</script>

</head>

<body>

<aside class="sidebar">

    <div class="logo">
        SME<span>360</span>
    </div>

    <div class="nav-title">Main Menu</div>

    <a href="dashboard.php" class="nav-link">
        <span>📊</span>
        <span>Dashboard</span>
    </a>

    <a href="reports.php" class="nav-link active">
        <span>📈</span>
        <span>Reports</span>
    </a>

</aside>

<main class="main">

<header class="topbar">

    <h1>Business Reports</h1>

    <div class="user">
        Welcome, <strong><?= htmlspecialchars($full_name) ?></strong>
    </div>

</header>

<div class="content">

<div class="page-header">

    <div>
        <h2><?= htmlspecialchars($reportTitle) ?></h2>
        <p>Analyze your business performance and financial activity.</p>
    </div>

    <button class="print-button" onclick="window.print()">
        Print Report
    </button>

</div>

<div class="filter-card">

<form method="GET" class="filter-form">

    <div class="filter-group">

        <label>Report Type</label>

        <select name="report_type" id="report_type" onchange="toggleCustomDates()">

            <option value="daily" <?= $report_type === "daily" ? "selected" : "" ?>>
                Daily
            </option>

            <option value="monthly" <?= $report_type === "monthly" ? "selected" : "" ?>>
                Monthly
            </option>

            <option value="yearly" <?= $report_type === "yearly" ? "selected" : "" ?>>
                Yearly
            </option>

            <option value="custom" <?= $report_type === "custom" ? "selected" : "" ?>>
                Custom Date Range
            </option>

        </select>

    </div>

    <div class="custom-dates" id="customDates">

        <div class="filter-group">

            <label>From Date</label>

            <input
                type="date"
                name="start_date"
                id="start_date"
                value="<?= htmlspecialchars($start_date) ?>"
                max="<?= htmlspecialchars($today) ?>"
                onchange="updateDateConstraints()"
            >

        </div>

        <div class="filter-group">

            <label>To Date</label>

            <input
                type="date"
                name="end_date"
                id="end_date"
                value="<?= htmlspecialchars($end_date) ?>"
                min="<?= htmlspecialchars($start_date) ?>"
                max="<?= htmlspecialchars($today) ?>"
                onchange="updateDateConstraints()"
            >

        </div>

    </div>

    <button type="submit" class="filter-button">
        Generate Report
    </button>

</form>

</div>

<div class="report-period">

    <h3>
        <?= htmlspecialchars($business["business_name"]) ?>
    </h3>

    <p>
        <?= htmlspecialchars($reportTitle) ?>:
        <?= formatDateDisplay($start_date) ?>
        -
        <?= formatDateDisplay($end_date) ?>
    </p>

</div>

<div class="stats-grid">

    <div class="stat-card">

        <div class="stat-label">Total Sales</div>

        <div class="stat-value green">
            <?= formatMoney($totalSales) ?>
        </div>

        <div class="stat-description">
            <?= number_format($salesCount) ?> completed sale(s)
        </div>

    </div>

    <div class="stat-card">

        <div class="stat-label">Money Received</div>

        <div class="stat-value blue">
            <?= formatMoney($amountReceived) ?>
        </div>

        <div class="stat-description">
            Actual payments received from sales
        </div>

    </div>

    <div class="stat-card">

        <div class="stat-label">Outstanding Credit</div>

        <div class="stat-value orange">
            <?= formatMoney($outstandingCredit) ?>
        </div>

        <div class="stat-description">
            Amount customers still owe from sales
        </div>

    </div>

    <div class="stat-card">

        <div class="stat-label">Gross Profit</div>

        <div class="stat-value <?= $grossProfit >= 0 ? "green" : "red" ?>">
            <?= formatMoney($grossProfit) ?>
        </div>

        <div class="stat-description">
            Selling price minus product cost
        </div>

    </div>

    <div class="stat-card">

        <div class="stat-label">Total Expenses</div>

        <div class="stat-value red">
            <?= formatMoney($totalExpenses) ?>
        </div>

        <div class="stat-description">
            <?= number_format($expenseCount) ?> expense record(s)
        </div>

    </div>

    <div class="stat-card">

        <div class="stat-label">Net Profit</div>

        <div class="stat-value <?= $netProfit >= 0 ? "green" : "red" ?>">
            <?= formatMoney($netProfit) ?>
        </div>

        <div class="stat-description">
            Gross profit minus expenses
        </div>

    </div>

    <div class="stat-card">

        <div class="stat-label">Items Sold</div>

        <div class="stat-value blue">
            <?= number_format($itemsSold, 3) ?>
        </div>

        <div class="stat-description">
            Total quantity sold
        </div>

    </div>

    <div class="stat-card">

        <div class="stat-label">Invoice Payments</div>

        <div class="stat-value green">
            <?= formatMoney($invoicePayments) ?>
        </div>

        <div class="stat-description">
            <?= number_format($invoicePaymentCount) ?> invoice payment(s)
        </div>

    </div>

</div>

<div class="section-grid">

<div class="card">

    <div class="card-header">

        <h3>Payment Status Breakdown</h3>

        <p>Sales grouped by payment status.</p>

    </div>

    <div class="table-wrapper">

    <table>

        <thead>

            <tr>
                <th>Status</th>
                <th>Sales</th>
                <th>Total</th>
                <th>Received</th>
                <th>Balance</th>
            </tr>

        </thead>

        <tbody>

        <?php if (empty($paymentStatuses)): ?>

            <tr>
                <td colspan="5" class="empty">
                    No payment data found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($paymentStatuses as $status): ?>

                <tr>

                    <td>
                        <span class="status status-<?= htmlspecialchars($status["payment_status"]) ?>">
                            <?= ucfirst($status["payment_status"]) ?>
                        </span>
                    </td>

                    <td><?= number_format($status["sale_count"]) ?></td>

                    <td><?= formatMoney($status["sale_value"]) ?></td>

                    <td><?= formatMoney($status["amount_paid"]) ?></td>

                    <td><?= formatMoney($status["balance"]) ?></td>

                </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>

    </table>

    </div>

</div>

<div class="card">

    <div class="card-header">

        <h3>Payment Methods</h3>

        <p>Sales and money received by payment method.</p>

    </div>

    <div class="table-wrapper">

    <table>

        <thead>

            <tr>
                <th>Method</th>
                <th>Sales</th>
                <th>Sale Value</th>
                <th>Received</th>
            </tr>

        </thead>

        <tbody>

        <?php if (empty($paymentMethods)): ?>

            <tr>
                <td colspan="4" class="empty">
                    No payment data found.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($paymentMethods as $method): ?>

                <tr>

                    <td>
                        <?= ucwords(str_replace("_", " ", $method["payment_method"])) ?>
                    </td>

                    <td><?= number_format($method["sale_count"]) ?></td>

                    <td><?= formatMoney($method["total_value"]) ?></td>

                    <td><strong><?= formatMoney($method["amount_received"]) ?></strong></td>

                </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>

    </table>

    </div>

</div>

</div>

<div class="section-grid">

<div class="card">

    <div class="card-header">

        <h3>Top Selling Products</h3>

        <p>Best-performing products during the selected period.</p>

    </div>

    <div class="table-wrapper">

    <table>

        <thead>

            <tr>
                <th>Product</th>
                <th>Type</th>
                <th>Qty Sold</th>
                <th>Sales</th>
                <th>Profit</th>
            </tr>

        </thead>

        <tbody>

        <?php if (empty($topProducts)): ?>

            <tr>
                <td colspan="5" class="empty">
                    No products sold during this period.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($topProducts as $product): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($product["product_name"]) ?>
                    </td>

                    <td>
                        <?= ucfirst($product["product_type"]) ?>
                    </td>

                    <td>
                        <?= number_format($product["quantity_sold"], 3) ?>
                    </td>

                    <td>
                        <?= formatMoney($product["sales_value"]) ?>
                    </td>

                    <td class="<?= $product["profit"] >= 0 ? "green" : "red" ?>">
                        <?= formatMoney($product["profit"]) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>

    </table>

    </div>

</div>

<div class="card">

    <div class="card-header">

        <h3>Expenses by Category</h3>

        <p>Where your business expenses were spent.</p>

    </div>

    <div class="table-wrapper">

    <table>

        <thead>

            <tr>
                <th>Category</th>
                <th>Records</th>
                <th>Total</th>
            </tr>

        </thead>

        <tbody>

        <?php if (empty($expenseCategories)): ?>

            <tr>
                <td colspan="3" class="empty">
                    No expenses recorded during this period.
                </td>
            </tr>

        <?php else: ?>

            <?php foreach ($expenseCategories as $category): ?>

                <tr>

                    <td>
                        <?= htmlspecialchars($category["category_name"]) ?>
                    </td>

                    <td>
                        <?= number_format($category["expense_count"]) ?>
                    </td>

                    <td>
                        <strong><?= formatMoney($category["total_amount"]) ?></strong>
                    </td>

                </tr>

            <?php endforeach; ?>

        <?php endif; ?>

        </tbody>

    </table>

    </div>

</div>

</div>

<div class="card">

<div class="card-header">

    <h3>Sales Details</h3>

    <p>
        Completed sales from
        <?= formatDateDisplay($start_date) ?>
        to
        <?= formatDateDisplay($end_date) ?>
    </p>

</div>

<div class="table-wrapper">

<table>

<thead>

<tr>
    <th>Sale ID</th>
    <th>Date</th>
    <th>Customer</th>
    <th>Total</th>
    <th>Received</th>
    <th>Balance</th>
    <th>Method</th>
    <th>Status</th>
</tr>

</thead>

<tbody>

<?php if (empty($salesDetails)): ?>

<tr>

    <td colspan="8" class="empty">
        No completed sales found for this period.
    </td>

</tr>

<?php else: ?>

<?php foreach ($salesDetails as $sale): ?>

<tr>

    <td>
        <strong><?= htmlspecialchars($sale["sale_id"]) ?></strong>
    </td>

    <td>
        <?= date("d M Y H:i", strtotime($sale["sale_date"])) ?>
    </td>

    <td>

        <?= htmlspecialchars(
            $sale["customer_name"] ?: "Walk-in Customer"
        ) ?>

        <?php if (!empty($sale["customer_phone"])): ?>

            <br>

            <small>
                <?= htmlspecialchars($sale["customer_phone"]) ?>
            </small>

        <?php endif; ?>

    </td>

    <td>
        <?= formatMoney($sale["total_amount"]) ?>
    </td>

    <td class="green">
        <?= formatMoney($sale["amount_paid"]) ?>
    </td>

    <td class="orange">
        <?= formatMoney($sale["balance"]) ?>
    </td>

    <td>
        <?= ucwords(str_replace("_", " ", $sale["payment_method"])) ?>
    </td>

    <td>

        <span class="status status-<?= htmlspecialchars($sale["payment_status"]) ?>">

            <?= ucfirst($sale["payment_status"]) ?>

        </span>

    </td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

<div class="card">

<div class="card-header">

    <h3>Expense Details</h3>

    <p>
        Active expenses from
        <?= formatDateDisplay($start_date) ?>
        to
        <?= formatDateDisplay($end_date) ?>
    </p>

</div>

<div class="table-wrapper">

<table>

<thead>

<tr>
    <th>Expense ID</th>
    <th>Date</th>
    <th>Category</th>
    <th>Description</th>
    <th>Payment Method</th>
    <th>Amount</th>
</tr>

</thead>

<tbody>

<?php if (empty($expenseDetails)): ?>

<tr>

    <td colspan="6" class="empty">
        No expenses found for this period.
    </td>

</tr>

<?php else: ?>

<?php foreach ($expenseDetails as $expense): ?>

<tr>

    <td>
        <?= htmlspecialchars($expense["expense_id"]) ?>
    </td>

    <td>
        <?= formatDateDisplay($expense["expense_date"]) ?>
    </td>

    <td>
        <?= htmlspecialchars($expense["category_name"]) ?>
    </td>

    <td>
        <?= htmlspecialchars($expense["description"] ?: "No description") ?>
    </td>

    <td>
        <?= ucwords(str_replace("_", " ", $expense["payment_method"])) ?>
    </td>

    <td class="red">
        <strong><?= formatMoney($expense["amount"]) ?></strong>
    </td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</div>

</main>

</body>
</html>