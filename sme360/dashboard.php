<?php
require_once "auth_check.php";
require_once "db.php";

$business_id = $_SESSION["business_id"];
$full_name = $_SESSION["full_name"];
$role_name = $_SESSION["role_name"];
$businessQuery = $pdo->prepare("
    SELECT business_name
    FROM businesses
    WHERE business_id = :business_id
    LIMIT 1
");

$businessQuery->execute([
    ":business_id" => $_SESSION["business_id"]
]);

$business_name = $businessQuery->fetchColumn() ?: "My Business";

$today = date("Y-m-d");

$salesQuery = $pdo->prepare("
    SELECT COALESCE(SUM(amount_paid), 0)
    FROM sales
    WHERE business_id = :business_id
    AND DATE(sale_date) = :today
    AND sale_status = 'completed'
");

$salesQuery->execute([
    ":business_id" => $business_id,
    ":today" => $today
]);

$todaySales = $salesQuery->fetchColumn();

$expenseQuery = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE business_id = :business_id
    AND expense_date = :today
    AND status = 'active'
");

$expenseQuery->execute([
    ":business_id" => $business_id,
    ":today" => $today
]);

$todayExpenses = $expenseQuery->fetchColumn();

$profitQuery = $pdo->prepare("
    SELECT COALESCE(SUM((si.unit_price - p.cost_price) * si.quantity), 0)
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.sale_id
    JOIN products p ON si.product_id = p.product_id
    WHERE s.business_id = :business_id
    AND DATE(s.sale_date) = :today
    AND s.sale_status = 'completed'
");

$profitQuery->execute([
    ":business_id" => $business_id,
    ":today" => $today
]);

$todayProfit = $profitQuery->fetchColumn();

$creditQuery = $pdo->prepare("
    SELECT COALESCE(SUM(balance), 0)
    FROM sales
    WHERE business_id = :business_id
    AND sale_status = 'completed'
    AND balance > 0
");

$creditQuery->execute([
    ":business_id" => $business_id
]);

$outstandingCredit = $creditQuery->fetchColumn();

$creditQuery->execute([
    ":business_id" => $business_id
]);

$outstandingCredit = $creditQuery->fetchColumn();

$productQuery = $pdo->prepare("
    SELECT COUNT(*)
    FROM products
    WHERE business_id = :business_id
    AND status = 'active'
");

$productQuery->execute([
    ":business_id" => $business_id
]);

$totalProducts = $productQuery->fetchColumn();

$lowStockQuery = $pdo->prepare("
    SELECT COUNT(*)
    FROM products
    WHERE business_id = :business_id
    AND product_type = 'product'
    AND status = 'active'
    AND current_stock <= minimum_stock
");

$lowStockQuery->execute([
    ":business_id" => $business_id
]);

$lowStockCount = $lowStockQuery->fetchColumn();

$salesCountQuery = $pdo->prepare("
    SELECT COUNT(*)
    FROM sales
    WHERE business_id = :business_id
    AND DATE(sale_date) = :today
    AND sale_status = 'completed'
");

$salesCountQuery->execute([
    ":business_id" => $business_id,
    ":today" => $today
]);

$todaySalesCount = $salesCountQuery->fetchColumn();

$recentSalesQuery = $pdo->prepare("
    SELECT
        s.sale_id,
        s.sale_date,
        s.total_amount,
        s.payment_method,
        s.payment_status,
        s.sale_status,
        COALESCE(c.customer_name, 'Walk-in Customer') AS customer_name
    FROM sales s
    LEFT JOIN customers c ON s.customer_phone = c.customer_id
    WHERE s.business_id = :business_id
    ORDER BY s.sale_date DESC
    LIMIT 5
");

$recentSalesQuery->execute([
    ":business_id" => $business_id
]);

$recentSales = $recentSalesQuery->fetchAll();

$lowStockProductsQuery = $pdo->prepare("
    SELECT
        product_id,
        product_name,
        current_stock,
        minimum_stock
    FROM products
    WHERE business_id = :business_id
    AND product_type = 'product'
    AND status = 'active'
    AND current_stock <= minimum_stock
    ORDER BY current_stock ASC
    LIMIT 5
");

$lowStockProductsQuery->execute([
    ":business_id" => $business_id
]);

$lowStockProducts = $lowStockProductsQuery->fetchAll();

function formatMoney($amount)
{
    return "K " . number_format((float) $amount, 2);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SME360</title>

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

        .layout {
            display: flex;
            min-height: 100vh;
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
            padding: 0 15px 30px;
            letter-spacing: 1px;
        }

        .logo span {
            color: #22c55e;
        }

        .business-name {
            padding: 15px;
            background: #1f2937;
            border-radius: 10px;
            margin-bottom: 25px;
        }

        .business-name small {
            display: block;
            color: #9ca3af;
            font-size: 11px;
            margin-bottom: 5px;
        }

        .business-name strong {
            font-size: 14px;
            line-height: 1.4;
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
            margin-bottom: 4px;
            font-size: 14px;
            transition: 0.2s;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #1f2937;
            color: #22c55e;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
        }

        .logout-link {
            margin-top: 30px;
            color: #f87171;
        }

        .main {
            margin-left: 250px;
            width: calc(100% - 250px);
            min-height: 100vh;
        }

        .topbar {
            background: white;
            height: 75px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            border-bottom: 1px solid #e5e7eb;
        }

        .page-title h1 {
            font-size: 22px;
            color: #111827;
        }

        .page-title p {
            color: #6b7280;
            font-size: 13px;
            margin-top: 4px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar {
            width: 40px;
            height: 40px;
            background: #dcfce7;
            color: #16a34a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .user-details strong {
            display: block;
            font-size: 14px;
        }

        .user-details span {
            color: #6b7280;
            font-size: 12px;
        }

        .content {
            padding: 30px 35px;
        }

        .welcome {
            background: #111827;
            color: white;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 25px;
        }

        .welcome h2 {
            font-size: 20px;
            margin-bottom: 7px;
        }

        .welcome p {
            color: #d1d5db;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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

        .green { color: #16a34a; }
        .red { color: #dc2626; }
        .orange { color: #ea580c; }
        .blue { color: #2563eb; }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
        }

        .card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 22px;
            border-bottom: 1px solid #e5e7eb;
        }

        .card-header h3 {
            font-size: 16px;
            color: #111827;
        }

        .card-header a {
            color: #16a34a;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 13px 20px;
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            background: #f9fafb;
        }

        td {
            padding: 15px 20px;
            border-top: 1px solid #f3f4f6;
            font-size: 13px;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-paid { background: #dcfce7; color: #15803d; }
        .status-unpaid { background: #fee2e2; color: #b91c1c; }
        .status-partial { background: #fef3c7; color: #b45309; }

        .stock-list {
            padding: 10px 22px;
        }

        .stock-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .stock-item:last-child {
            border-bottom: none;
        }

        .stock-name {
            font-size: 13px;
            font-weight: 600;
        }

        .stock-min {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 4px;
        }

        .stock-quantity {
            color: #dc2626;
            font-size: 13px;
            font-weight: 700;
        }

        .empty-state {
            padding: 30px;
            text-align: center;
            color: #9ca3af;
            font-size: 13px;
        }

        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 800px) {
            .sidebar {
                width: 70px;
                padding: 20px 10px;
            }

            .logo {
                font-size: 0;
                text-align: center;
                padding: 0 0 25px;
            }

            .logo::before {
                content: "S";
                font-size: 25px;
                color: #22c55e;
            }

            .business-name,
            .nav-title {
                display: none;
            }

            .nav-link {
                justify-content: center;
                padding: 12px;
            }

            .nav-link span:not(.nav-icon) {
                display: none;
            }

            .main {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .topbar {
                padding: 0 20px;
            }

            .content {
                padding: 20px;
            }
        }

        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: 1fr; }
            .user-details { display: none; }
        }
    </style>
</head>

<body>

<div class="layout">

    <aside class="sidebar">

        <div class="logo">
            SME<span>360</span>
        </div>

        <div class="business-name">
            <small>BUSINESS</small>
            <strong><?= htmlspecialchars($business_name) ?></strong>
        </div>

        <div class="nav-title">Main Menu</div>

        <a href="dashboard.php" class="nav-link active">
            <span class="nav-icon">📊</span>
            <span>Dashboard</span>
        </a>

        <a href="products.php" class="nav-link">
            <span class="nav-icon">🛒</span>
            <span>Products</span>
        </a>

        <a href="inventory.php" class="nav-link">
            <span class="nav-icon">📦</span>
            <span>Inventory</span>
        </a>

        <a href="sales.php" class="nav-link">
            <span class="nav-icon">$</span>
            <span>Sales</span>
        </a>

        <div class="nav-title">Finance</div>

        <a href="expenses.php" class="nav-link">
            <span class="nav-icon">💰</span>
            <span>Expenses</span>
        </a>

        <a href="invoices.php" class="nav-link">
            <span class="nav-icon">🧾</span>
            <span>Invoices</span>
        </a>

        <a href="payments.php" class="nav-link">
            <span class="nav-icon">$</span>
            <span>Payments</span>
        </a>

        <div class="nav-title">Management</div>

        <a href="reports.php" class="nav-link">
            <span class="nav-icon">📈</span>
            <span>Reports</span>
        </a>

        <a href="users.php" class="nav-link">
            <span class="nav-icon">👥</span>
            <span>Users</span>
        </a>

        <a href="settings.php" class="nav-link">
            <span class="nav-icon">⚙️</span>
            <span>Settings</span>
        </a>

        <a href="logout.php" class="nav-link logout-link">
            <span class="nav-icon">↪</span>
            <span>Logout</span>
        </a>

    </aside>

    <main class="main">

        <header class="topbar">

            <div class="page-title">
                <h1>Dashboard</h1>
                <p><?= date("l, d F Y") ?></p>
            </div>

            <div class="user-info">
                <div class="avatar">
                    <?= strtoupper(substr($full_name, 0, 1)) ?>
                </div>

                <div class="user-details">
                    <strong><?= htmlspecialchars($full_name) ?></strong>
                    <span><?= htmlspecialchars($role_name) ?></span>
                </div>
            </div>

        </header>

        <div class="content">

            <div class="welcome">
                <h2>Welcome back, <?= htmlspecialchars($full_name) ?>!</h2>
                <p>Here's what's happening with your business today.</p>
            </div>

            <div class="stats-grid">

                <div class="stat-card">
                    <div class="stat-label">Today's Sales</div>
                    <div class="stat-value green"><?= formatMoney($todaySales) ?></div>
                    <div class="stat-description"><?= $todaySalesCount ?> completed sale(s) today</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Today's Expenses</div>
                    <div class="stat-value red"><?= formatMoney($todayExpenses) ?></div>
                    <div class="stat-description">Total expenses recorded today</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Today's Profit</div>
                    <div class="stat-value <?= $todayProfit >= 0 ? "green" : "red" ?>">
                        <?= formatMoney($todayProfit) ?>
                    </div>
                    <div class="stat-description">Sale price minus cost price on today's sales</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Outstanding Credit</div>
                    <div class="stat-value orange"><?= formatMoney($outstandingCredit) ?></div>
                    <div class="stat-description">Unpaid customer balances</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Total Products</div>
                    <div class="stat-value blue"><?= number_format($totalProducts) ?></div>
                    <div class="stat-description">Active products and services</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Low Stock</div>
                    <div class="stat-value red"><?= number_format($lowStockCount) ?></div>
                    <div class="stat-description">Products needing attention</div>
                </div>
            </div>
            <div class="dashboard-grid">

                <div class="card">

                    <div class="card-header">
                        <h3>Recent Sales</h3>
                        <a href="sales.php">View All</a>
                    </div>

                    <?php if (!empty($recentSales)): ?>

                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Sale</th>
                                        <th>Customer</th>
                                        <th>Amount</th>
                                        <th>Payment</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentSales as $sale): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($sale["sale_id"]) ?></strong>
                                            <br>
                                            <small><?= date("d M Y, H:i", strtotime($sale["sale_date"])) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($sale["customer_name"]) ?></td>
                                        <td><strong><?= formatMoney($sale["total_amount"]) ?></strong></td>
                                        <td><?= ucwords(str_replace("_", " ", $sale["payment_method"])) ?></td>
                                        <td>
                                            <?php
                                            $statusClass = "status-paid";

                                            if ($sale["payment_status"] === "unpaid") {
                                                $statusClass = "status-unpaid";
                                            } elseif ($sale["payment_status"] === "partial") {
                                                $statusClass = "status-partial";
                                            }
                                            ?>
                                            <span class="status <?= $statusClass ?>">
                                                <?= ucfirst($sale["payment_status"]) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">No sales have been recorded yet.</div>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3>Low Stock</h3>
                        <a href="inventory.php">View Inventory</a>
                    </div>

                    <?php if (!empty($lowStockProducts)): ?>

                        <div class="stock-list">
                            <?php foreach ($lowStockProducts as $product): ?>
                                <div class="stock-item">
                                    <div>
                                        <div class="stock-name"><?= htmlspecialchars($product["product_name"]) ?></div>
                                        <div class="stock-min">Minimum: <?= number_format($product["minimum_stock"], 2) ?></div>
                                    </div>
                                    <div class="stock-quantity"><?= number_format($product["current_stock"], 2) ?> left</div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>

                        <div class="empty-state">
                            <div>✓</div>
                            No low-stock products.
                        </div>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>