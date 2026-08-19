<?php

require_once "auth_check.php";
require_once "db.php";

$business_id = $_SESSION["business_id"];
$full_name = $_SESSION["full_name"];

$message = "";
$error = "";


/*
|--------------------------------------------------------------------------
| Generate Expense ID
|--------------------------------------------------------------------------
*/

function generateExpenseID($pdo)
{
    $prefix = "EXP";

    $query = $pdo->query("
        SELECT expense_id
        FROM expenses
        ORDER BY expense_id DESC
        LIMIT 1
    ");

    $lastID = $query->fetchColumn();

    if (!$lastID) {
        return $prefix . "0001";
    }

    $number = (int) substr($lastID, strlen($prefix));
    $number++;

    return $prefix . str_pad($number, 4, "0", STR_PAD_LEFT);
}


/*
|--------------------------------------------------------------------------
| Success Message
|--------------------------------------------------------------------------
*/

if (isset($_GET["updated"]) && $_GET["updated"] === "1") {
    $message = "Expense updated successfully.";
}

if (isset($_GET["cancelled"]) && $_GET["cancelled"] === "1") {
    $message = "Expense cancelled successfully.";
}


/*
|--------------------------------------------------------------------------
| Add Expense
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_expense"])) {

    $expense_category_id = trim($_POST["expense_category_id"] ?? "");
    $amount = $_POST["amount"] ?? 0;
    $description = trim($_POST["description"] ?? "");
    $payment_method = $_POST["payment_method"] ?? "cash";
    $expense_date = $_POST["expense_date"] ?? date("Y-m-d");

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($expense_category_id === "") {

        $error = "Please select an expense category.";

    } elseif (!is_numeric($amount) || (float)$amount <= 0) {

        $error = "Please enter a valid expense amount.";

    } elseif (
        !in_array(
            $payment_method,
            [
                "cash",
                "mobile_money",
                "bank_transfer",
                "debit_card",
                "credit_card",
                "other"
            ],
            true
        )
    ) {

        $error = "Invalid payment method.";

    } elseif ($expense_date === "") {

        $error = "Please select an expense date.";

    }


    /*
    |--------------------------------------------------------------------------
    | Verify Expense Category
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        $categoryCheck = $pdo->prepare("
            SELECT expense_category_id
            FROM expense_categories
            WHERE expense_category_id = :expense_category_id
            AND status = 'active'
            LIMIT 1
        ");

        $categoryCheck->execute([
            ":expense_category_id" => $expense_category_id
        ]);

        if (!$categoryCheck->fetch()) {

            $error = "Invalid expense category.";

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Save Expense
    |--------------------------------------------------------------------------
    */

    if ($error === "") {

        try {

            $expense_id = generateExpenseID($pdo);

            $insert = $pdo->prepare("
                INSERT INTO expenses (
                    expense_id,
                    business_id,
                    expense_category_id,
                    user_id,
                    amount,
                    description,
                    payment_method,
                    expense_date,
                    status
                )
                VALUES (
                    :expense_id,
                    :business_id,
                    :expense_category_id,
                    :user_id,
                    :amount,
                    :description,
                    :payment_method,
                    :expense_date,
                    'active'
                )
            ");

            $insert->execute([
                ":expense_id" => $expense_id,
                ":business_id" => $business_id,
                ":expense_category_id" => $expense_category_id,
                ":user_id" => $_SESSION["user_id"] ?? null,
                ":amount" => (float)$amount,
                ":description" => $description !== "" ? $description : null,
                ":payment_method" => $payment_method,
                ":expense_date" => $expense_date
            ]);

            $message = "Expense added successfully.";

        } catch (PDOException $e) {

            $error = "Unable to add expense. Database Error: " . $e->getMessage();

        }

    }

}


/*
|--------------------------------------------------------------------------
| Cancel Expense
|--------------------------------------------------------------------------
*/

if (isset($_GET["cancel"]) && $_GET["cancel"] !== "") {

    $expense_id = trim($_GET["cancel"]);

    try {

        $cancelQuery = $pdo->prepare("
            UPDATE expenses
            SET status = 'cancelled'
            WHERE expense_id = :expense_id
            AND business_id = :business_id
        ");

        $cancelQuery->execute([
            ":expense_id" => $expense_id,
            ":business_id" => $business_id
        ]);

        header("Location: expenses.php?cancelled=1");
        exit;

    } catch (PDOException $e) {

        $error = "Unable to cancel expense.";

    }

}


/*
|--------------------------------------------------------------------------
| Get Expense Categories
|--------------------------------------------------------------------------
*/

$categoryQuery = $pdo->prepare("
    SELECT
        expense_category_id,
        category_name
    FROM expense_categories
    WHERE status = 'active'
    ORDER BY category_name ASC
");

$categoryQuery->execute();

$categories = $categoryQuery->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Search and Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");

$filter_category = trim($_GET["category"] ?? "");

$filter_status = trim($_GET["status"] ?? "");

$filter_date = trim($_GET["expense_date"] ?? "");


/*
|--------------------------------------------------------------------------
| Expense Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        e.expense_id,
        e.amount,
        e.description,
        e.payment_method,
        e.expense_date,
        e.status,
        e.created_at,
        ec.category_name
    FROM expenses e
    LEFT JOIN expense_categories ec
        ON e.expense_category_id = ec.expense_category_id
    WHERE e.business_id = :business_id
";

$params = [
    ":business_id" => $business_id
];


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $sql .= "
        AND (
            e.expense_id LIKE :search
            OR e.description LIKE :search
            OR ec.category_name LIKE :search
        )
    ";

    $params[":search"] = "%" . $search . "%";

}


/*
|--------------------------------------------------------------------------
| Category Filter
|--------------------------------------------------------------------------
*/

if ($filter_category !== "") {

    $sql .= "
        AND e.expense_category_id = :category_id
    ";

    $params[":category_id"] = $filter_category;

}


/*
|--------------------------------------------------------------------------
| Status Filter
|--------------------------------------------------------------------------
*/

if ($filter_status !== "") {

    $sql .= "
        AND e.status = :status
    ";

    $params[":status"] = $filter_status;

}


/*
|--------------------------------------------------------------------------
| Date Filter
|--------------------------------------------------------------------------
*/

if ($filter_date !== "") {

    $sql .= "
        AND e.expense_date = :expense_date
    ";

    $params[":expense_date"] = $filter_date;

}


$sql .= "
    ORDER BY e.expense_date DESC, e.created_at DESC
";


$expenseQuery = $pdo->prepare($sql);

$expenseQuery->execute($params);

$expenses = $expenseQuery->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Expense Statistics
|--------------------------------------------------------------------------
*/

$totalExpensesQuery = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE business_id = :business_id
    AND status = 'active'
");

$totalExpensesQuery->execute([
    ":business_id" => $business_id
]);

$totalExpenses = $totalExpensesQuery->fetchColumn();


/*
|--------------------------------------------------------------------------
| Today's Expenses
|--------------------------------------------------------------------------
*/

$todayExpensesQuery = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE business_id = :business_id
    AND expense_date = :today
    AND status = 'active'
");

$todayExpensesQuery->execute([
    ":business_id" => $business_id,
    ":today" => date("Y-m-d")
]);

$todayExpenses = $todayExpensesQuery->fetchColumn();


/*
|--------------------------------------------------------------------------
| Cancelled Expenses
|--------------------------------------------------------------------------
*/

$cancelledExpensesQuery = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE business_id = :business_id
    AND status = 'cancelled'
");

$cancelledExpensesQuery->execute([
    ":business_id" => $business_id
]);

$cancelledExpenses = $cancelledExpensesQuery->fetchColumn();


/*
|--------------------------------------------------------------------------
| Format Money
|--------------------------------------------------------------------------
*/

function formatMoney($amount)
{
    return "K " . number_format((float)$amount, 2);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Expenses | SME360</title>


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


/* SIDEBAR */

.sidebar {
    width: 250px;
    background: #111827;
    color: white;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    padding: 25px 18px;
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

.nav-title {
    color: #6b7280;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    padding: 0 15px;
    margin: 20px 0 10px;
}

.logout {
    margin-top: 30px;
    color: #f87171;
}


/* MAIN */

.main {
    margin-left: 250px;
    min-height: 100vh;
}


/* TOPBAR */

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


/* CONTENT */

.content {
    padding: 30px 35px;
}


/* PAGE HEADER */

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

.add-button {
    background: #22c55e;
    color: white;
    border: none;
    padding: 12px 18px;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
}

.add-button:hover {
    background: #16a34a;
}


/* MESSAGES */

.message {
    padding: 13px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
}

.success {
    background: #dcfce7;
    color: #166534;
}

.error {
    background: #fee2e2;
    color: #991b1b;
}


/* STATISTICS */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
}

.stat-label {
    color: #6b7280;
    font-size: 13px;
    margin-bottom: 10px;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
}

.stat-description {
    font-size: 12px;
    color: #9ca3af;
    margin-top: 8px;
}

.green {
    color: #16a34a;
}

.red {
    color: #dc2626;
}

.orange {
    color: #ea580c;
}


/* FORM */

.form-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    margin-bottom: 25px;
    display: none;
}

.form-card.show {
    display: block;
}

.form-card h3 {
    font-size: 18px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full {
    grid-column: 1 / -1;
}

label {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 7px;
}

input,
select,
textarea {
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font-size: 14px;
    outline: none;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #22c55e;
}

textarea {
    min-height: 80px;
    resize: vertical;
}

.form-actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
}

.submit-button {
    background: #22c55e;
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
}

.cancel-button {
    background: #e5e7eb;
    color: #374151;
    border: none;
    padding: 12px 20px;
    border-radius: 7px;
    cursor: pointer;
}


/* FILTERS */

.filters {
    background: white;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    margin-bottom: 20px;
}

.filter-form {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
    gap: 10px;
}

.filter-button {
    background: #111827;
    color: white;
    border: none;
    padding: 0 20px;
    border-radius: 7px;
    cursor: pointer;
}

.clear-button {
    display: flex;
    align-items: center;
    padding: 0 15px;
    color: #6b7280;
    text-decoration: none;
    font-size: 13px;
}


/* TABLE */

.table-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
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
    padding: 14px 18px;
    font-size: 11px;
    text-transform: uppercase;
    color: #6b7280;
}

td {
    padding: 15px 18px;
    border-top: 1px solid #f3f4f6;
    font-size: 13px;
}

.expense-id {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 3px;
}

.amount {
    font-weight: 700;
    color: #dc2626;
}

.status {
    padding: 5px 9px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 600;
}

.status-active {
    background: #dcfce7;
    color: #15803d;
}

.status-cancelled {
    background: #fee2e2;
    color: #b91c1c;
}

.action-link {
    color: #2563eb;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}

.cancel-link {
    color: #dc2626;
}

.action-sep {
    color: #d1d5db;
    margin: 0 6px;
    font-size: 12px;
}

.empty {
    text-align: center;
    padding: 50px;
    color: #9ca3af;
}


/* RESPONSIVE */

@media (max-width: 1100px) {

    .filter-form {
        grid-template-columns: 1fr 1fr;
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

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
    }

    .filter-form {
        grid-template-columns: 1fr;
    }

    .filter-button,
    .clear-button {
        padding: 12px;
        justify-content: center;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

}

</style>


<script>

function toggleForm() {

    const form = document.getElementById("addExpenseForm");

    form.classList.toggle("show");

}

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

    <a href="expenses.php" class="nav-link active">
        <span>↓</span>
        <span>Expenses</span>
    </a>

</aside>


<main class="main">


<header class="topbar">

    <div>
        <h1>Expenses</h1>
    </div>


    <div class="user">

        Welcome,
        <strong>
            <?= htmlspecialchars($full_name) ?>
        </strong>

    </div>

</header>


<div class="content">


<div class="page-header">

    <div>

        <h2>Business Expenses</h2>

        <p>
            Record and manage expenses incurred by your business.
        </p>

    </div>


    <button
        class="add-button"
        onclick="toggleForm()"
    >
        + Add Expense
    </button>

</div>


<?php if ($message !== ""): ?>

<div class="message success">
    <?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>


<?php if ($error !== ""): ?>

<div class="message error">
    <?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>


<!-- STATISTICS -->

<div class="stats-grid">


<div class="stat-card">

    <div class="stat-label">
        Today's Expenses
    </div>

    <div class="stat-value red">
        <?= formatMoney($todayExpenses) ?>
    </div>

    <div class="stat-description">
        Active expenses recorded today
    </div>

</div>


<div class="stat-card">

    <div class="stat-label">
        Total Expenses
    </div>

    <div class="stat-value orange">
        <?= formatMoney($totalExpenses) ?>
    </div>

    <div class="stat-description">
        Total active business expenses
    </div>

</div>


<div class="stat-card">

    <div class="stat-label">
        Cancelled Expenses
    </div>

    <div class="stat-value">
        <?= formatMoney($cancelledExpenses) ?>
    </div>

    <div class="stat-description">
        Expenses marked as cancelled
    </div>

</div>


</div>


<!-- ADD EXPENSE FORM -->

<div
    class="form-card"
    id="addExpenseForm"
>


<h3>
    Add Business Expense
</h3>

<br>


<form
    method="POST"
    action=""
>


<div class="form-grid">


<div class="form-group">

<label>
    Expense Category *
</label>

<select
    name="expense_category_id"
    required
>

<option value="">
    Select Category
</option>


<?php foreach ($categories as $category): ?>

<option
    value="<?= htmlspecialchars($category["expense_category_id"]) ?>"
>

    <?= htmlspecialchars($category["category_name"]) ?>

</option>

<?php endforeach; ?>


</select>

</div>


<div class="form-group">

<label>
    Amount *
</label>

<input
    type="number"
    name="amount"
    min="0.01"
    step="0.01"
    placeholder="e.g. 500.00"
    required
>

</div>


<div class="form-group">

<label>
    Payment Method *
</label>

<select
    name="payment_method"
    required
>

<option value="cash">
    Cash
</option>

<option value="mobile_money">
    Mobile Money
</option>

<option value="bank_transfer">
    Bank Transfer
</option>

<option value="debit_card">
    Debit Card
</option>

<option value="credit_card">
    Credit Card
</option>

<option value="other">
    Other
</option>

</select>

</div>


<div class="form-group">

<label>
    Expense Date *
</label>

<input
    type="date"
    name="expense_date"
    value="<?= date("Y-m-d") ?>"
    required
>

</div>


<div class="form-group full">

<label>
    Description / Notes
</label>

<textarea
    name="description"
    placeholder="Describe what the expense was for..."
></textarea>

</div>


</div>


<div class="form-actions">

<button
    type="submit"
    name="add_expense"
    class="submit-button"
>
    Save Expense
</button>


<button
    type="button"
    class="cancel-button"
    onclick="toggleForm()"
>
    Cancel
</button>

</div>


</form>

</div>


<!-- FILTERS -->

<div class="filters">


<form
    method="GET"
    class="filter-form"
>


<input
    type="text"
    name="search"
    placeholder="Search expense ID, category or description"
    value="<?= htmlspecialchars($search) ?>"
>


<select name="category">

<option value="">
    All Categories
</option>


<?php foreach ($categories as $category): ?>

<option
    value="<?= htmlspecialchars($category["expense_category_id"]) ?>"
    <?= $filter_category === $category["expense_category_id"] ? "selected" : "" ?>
>

    <?= htmlspecialchars($category["category_name"]) ?>

</option>

<?php endforeach; ?>

</select>


<select name="status">

<option value="">
    All Statuses
</option>

<option
    value="active"
    <?= $filter_status === "active" ? "selected" : "" ?>
>
    Active
</option>

<option
    value="cancelled"
    <?= $filter_status === "cancelled" ? "selected" : "" ?>
>
    Cancelled
</option>

</select>


<input
    type="date"
    name="expense_date"
    value="<?= htmlspecialchars($filter_date) ?>"
>


<button
    type="submit"
    class="filter-button"
>
    Search
</button>


<a
    href="expenses.php"
    class="clear-button"
>
    Clear
</a>


</form>

</div>


<!-- EXPENSE TABLE -->

<div class="table-card">


<div class="table-wrapper">


<table>


<thead>

<tr>

<th>
    Expense
</th>

<th>
    Category
</th>

<th>
    Amount
</th>

<th>
    Payment Method
</th>

<th>
    Date
</th>

<th>
    Status
</th>

<th>
    Action
</th>

</tr>

</thead>


<tbody>


<?php if (empty($expenses)): ?>


<tr>

<td
    colspan="7"
    class="empty"
>
    No expenses found.
</td>

</tr>


<?php else: ?>


<?php foreach ($expenses as $expense): ?>


<tr>


<td>

<strong>
    <?= htmlspecialchars($expense["expense_id"]) ?>
</strong>

<?php if (!empty($expense["description"])): ?>

<div class="expense-id">

    <?= htmlspecialchars($expense["description"]) ?>

</div>

<?php endif; ?>

</td>


<td>

<?= htmlspecialchars(
    $expense["category_name"] ?? "Uncategorized"
) ?>

</td>


<td>

<span class="amount">

<?= formatMoney($expense["amount"]) ?>

</span>

</td>


<td>

<?= ucwords(
    str_replace(
        "_",
        " ",
        $expense["payment_method"]
    )
) ?>

</td>


<td>

<?= date(
    "d M Y",
    strtotime($expense["expense_date"])
) ?>

</td>


<td>

<span
    class="status status-<?= htmlspecialchars($expense["status"]) ?>"
>

<?= ucfirst(
    $expense["status"]
) ?>

</span>

</td>


<td>


<?php if ($expense["status"] === "active"): ?>


<a
    href="edit-expense.php?id=<?= urlencode($expense["expense_id"]) ?>"
    class="action-link"
>
    Edit
</a>


<span class="action-sep">
    |
</span>


<a
    href="expenses.php?cancel=<?= urlencode($expense["expense_id"]) ?>"
    class="action-link cancel-link"
    onclick="return confirm('Are you sure you want to cancel this expense?');"
>
    Cancel
</a>


<?php else: ?>


<span style="color:#9ca3af;">
    No actions
</span>


<?php endif; ?>


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