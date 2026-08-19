<?php
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id'], $_SESSION['business_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'];
$role = $_SESSION['role'] ?? 'Staff';


$success = '';
$error = '';

function generatePaymentId(PDO $pdo): string {
    $stmt = $pdo->query("
        SELECT payment_id FROM payments
        WHERE payment_id LIKE 'PAY%'
        ORDER BY CAST(SUBSTRING(payment_id, 4) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $last = $stmt->fetchColumn();
    $number = $last ? (int)substr($last, 3) + 1 : 1;
    return 'PAY' . str_pad($number, 6, '0', STR_PAD_LEFT);
}

function createAuditLog(PDO $pdo, string $business_id, ?string $user_id, string $action, string $table, string $record_id, string $description): void {
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs
        (business_id, user_id, action, table_name, record_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $business_id,
        $user_id,
        $action,
        $table,
        $record_id,
        $description
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'record_payment') {
        $invoice_id = trim($_POST['invoice_id'] ?? '');
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? '');
        $reference = trim($_POST['reference'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($invoice_id === '') {
            $error = "Please select an invoice.";
        } elseif ($amount <= 0) {
            $error = "Payment amount must be greater than zero.";
        } elseif (!in_array($payment_method, ['Cash', 'Mobile Money', 'Bank Transfer', 'Card'], true)) {
            $error = "Please select a valid payment method.";
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT invoice_id, invoice_number, total_amount, amount_paid, balance, status
                    FROM invoices
                    WHERE invoice_id = ?
                    AND business_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$invoice_id, $business_id]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$invoice) {
                    throw new Exception("Invoice not found.");
                }

                if ($invoice['status'] === 'cancelled') {
                    throw new Exception("Payments cannot be recorded against a cancelled invoice.");
                }

                if ($invoice['balance'] <= 0) {
                    throw new Exception("This invoice has already been fully paid.");
                }

                if ($amount > (float)$invoice['balance']) {
                    throw new Exception(
                        "Payment cannot exceed the outstanding balance of " .
                        number_format((float)$invoice['balance'], 2)
                    );
                }

                $payment_id = generatePaymentId($pdo);

                $stmt = $pdo->prepare("
                    INSERT INTO payments
                    (payment_id, business_id, invoice_id, user_id, payment_date,
                     amount, payment_method, reference, notes, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
                ");

                $stmt->execute([
                    $payment_id,
                    $business_id,
                    $invoice_id,
                    $user_id,
                    $payment_date,
                    $amount,
                    $payment_method,
                    $reference ?: null,
                    $notes ?: null
                ]);

                $new_amount_paid = (float)$invoice['amount_paid'] + $amount;
                $new_balance = (float)$invoice['total_amount'] - $new_amount_paid;

                if ($new_balance <= 0) {
                    $new_balance = 0;
                    $new_status = 'paid';
                } else {
                    $new_status = 'partially_paid';
                }

                $stmt = $pdo->prepare("
                    UPDATE invoices
                    SET amount_paid = ?, balance = ?, status = ?
                    WHERE invoice_id = ?
                    AND business_id = ?
                ");

                $stmt->execute([
                    $new_amount_paid,
                    $new_balance,
                    $new_status,
                    $invoice_id,
                    $business_id
                ]);

                createAuditLog(
                    $pdo,
                    $business_id,
                    $user_id,
                    'Payment Recorded',
                    'payments',
                    $payment_id,
                    "Recorded {$amount} payment for invoice {$invoice['invoice_number']}."
                );

                $pdo->commit();

                $success = "Payment recorded successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $e->getMessage();
            }
        }
    }

    if ($action === 'reverse_payment') {
        $payment_id = trim($_POST['payment_id'] ?? '');

        if ($payment_id === '') {
            $error = "Invalid payment.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT
                        p.payment_id,
                        p.invoice_id,
                        p.amount,
                        p.status,
                        i.invoice_number,
                        i.total_amount,
                        i.amount_paid
                    FROM payments p
                    INNER JOIN invoices i ON p.invoice_id = i.invoice_id
                    WHERE p.payment_id = ?
                    AND p.business_id = ?
                    AND i.business_id = ?
                    FOR UPDATE
                ");

                $stmt->execute([
                    $payment_id,
                    $business_id,
                    $business_id
                ]);

                $payment = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$payment) {
                    throw new Exception("Payment not found.");
                }

                if ($payment['status'] !== 'completed') {
                    throw new Exception("This payment has already been reversed.");
                }

                $new_amount_paid = max(
                    0,
                    (float)$payment['amount_paid'] - (float)$payment['amount']
                );

                $new_balance = max(
                    0,
                    (float)$payment['total_amount'] - $new_amount_paid
                );

                if ($new_amount_paid <= 0) {
                    $new_status = 'issued';
                } elseif ($new_balance <= 0) {
                    $new_status = 'paid';
                } else {
                    $new_status = 'partially_paid';
                }

                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET status = 'reversed'
                    WHERE payment_id = ?
                    AND business_id = ?
                ");

                $stmt->execute([
                    $payment_id,
                    $business_id
                ]);

                $stmt = $pdo->prepare("
                    UPDATE invoices
                    SET amount_paid = ?, balance = ?, status = ?
                    WHERE invoice_id = ?
                    AND business_id = ?
                ");

                $stmt->execute([
                    $new_amount_paid,
                    $new_balance,
                    $new_status,
                    $payment['invoice_id'],
                    $business_id
                ]);

                createAuditLog(
                    $pdo,
                    $business_id,
                    $user_id,
                    'Payment Reversed',
                    'payments',
                    $payment_id,
                    "Reversed payment for invoice {$payment['invoice_number']}."
                );

                $pdo->commit();

                $success = "Payment reversed successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $error = $e->getMessage();
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$method_filter = trim($_GET['payment_method'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = ["p.business_id = ?"];
$params = [$business_id];

if ($search !== '') {
    $where[] = "(p.payment_id LIKE ? OR i.invoice_number LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ?)";
    $term = "%{$search}%";

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($method_filter !== '') {
    $where[] = "p.payment_method = ?";
    $params[] = $method_filter;
}

if ($status_filter !== '') {
    $where[] = "p.status = ?";
    $params[] = $status_filter;
}

if ($date_from !== '') {
    $where[] = "p.payment_date >= ?";
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where[] = "p.payment_date <= ?";
    $params[] = $date_to;
}

$where_sql = implode(" AND ", $where);

$stmt = $pdo->prepare("
    SELECT
        p.payment_id,
        p.payment_date,
        p.amount,
        p.payment_method,
        p.reference,
        p.notes,
        p.status,
        i.invoice_id,
        i.invoice_number,
        c.customer_name,
        c.phone,
        u.full_name
    FROM payments p
    INNER JOIN invoices i ON p.invoice_id = i.invoice_id
    INNER JOIN customers c ON i.customer_id = c.customer_id
    LEFT JOIN users u ON p.user_id = u.user_id
    WHERE {$where_sql}
    ORDER BY p.payment_date DESC, p.created_at DESC
");

$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS total_received,
        COALESCE(SUM(CASE WHEN status = 'completed' AND payment_date = CURDATE() THEN amount ELSE 0 END), 0) AS today_received,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_payments,
        COUNT(CASE WHEN status = 'reversed' THEN 1 END) AS reversed_payments
    FROM payments
    WHERE business_id = ?
");

$stmt->execute([$business_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        i.invoice_id,
        i.invoice_number,
        i.total_amount,
        i.amount_paid,
        i.balance,
        i.invoice_date,
        i.due_date,
        c.customer_name,
        c.phone
    FROM invoices i
    INNER JOIN customers c ON i.customer_id = c.customer_id
    WHERE i.business_id = ?
    AND i.balance > 0
    AND i.status != 'cancelled'
    ORDER BY i.invoice_date DESC
");

$stmt->execute([$business_id]);
$outstanding_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currency_symbol = 'K';

$stmt = $pdo->prepare("
    SELECT currency_symbol
    FROM business_settings
    WHERE business_id = ?
    LIMIT 1
");

$stmt->execute([$business_id]);
$currency = $stmt->fetchColumn();

if ($currency) {
    $currency_symbol = $currency;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payments | SME360</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Inter,sans-serif;background:#f5f7fb;color:#172033}
button,input,select,textarea{font-family:inherit}
.sidebar{position:fixed;top:0;left:0;width:245px;height:100vh;background:#111827;color:#fff;padding:25px 18px;z-index:1000}
.logo{font-size:25px;font-weight:800;margin-bottom:35px;padding-left:12px}
.logo span{color:#10b981}
.nav-title{font-size:11px;color:#94a3b8;text-transform:uppercase;margin:20px 12px 10px;letter-spacing:1px}
.nav-link{display:flex;align-items:center;gap:12px;text-decoration:none;color:#cbd5e1;padding:12px;border-radius:9px;margin-bottom:5px;font-size:14px}
.nav-link:hover,.nav-link.active{background:#1f2937;color:#10b981}
.main{margin-left:245px;padding:30px}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px}
.page-title h1{font-size:28px;font-weight:800}
.page-title p{color:#64748b;font-size:14px;margin-top:5px}
.user-info{display:flex;align-items:center;gap:12px}
.user-avatar{width:42px;height:42px;background:#10b981;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700}
.user-details strong{display:block;font-size:14px}
.user-details span{color:#64748b;font-size:12px}
.alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:14px}
.alert.success{background:#dcfce7;color:#166534}
.alert.error{background:#fee2e2;color:#991b1b}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:25px}
.summary-card{background:#fff;padding:22px;border-radius:14px;box-shadow:0 4px 15px rgba(0,0,0,.04)}
.summary-card .label{color:#64748b;font-size:13px;margin-bottom:10px}
.summary-card .value{font-size:24px;font-weight:800}
.card{background:#fff;border-radius:14px;padding:22px;margin-bottom:25px;box-shadow:0 4px 15px rgba(0,0,0,.04)}
.card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.card-header h2{font-size:18px}
.btn{border:0;padding:10px 16px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600}
.btn-primary{background:#10b981;color:#fff}
.btn-primary:hover{background:#059669}
.btn-secondary{background:#e2e8f0;color:#334155}
.btn-danger{background:#fee2e2;color:#b91c1c}
.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:10px;margin-bottom:20px}
input,select,textarea{width:100%;border:1px solid #dbe2ea;padding:11px 13px;border-radius:8px;outline:0;background:#fff;font-size:13px}
input:focus,select:focus,textarea:focus{border-color:#10b981}
.table-container{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{text-align:left;background:#f8fafc;color:#64748b;font-size:11px;text-transform:uppercase;padding:13px}
td{padding:14px 13px;border-bottom:1px solid #eef2f7;font-size:13px}
.payment-id{font-weight:700;color:#172033}
.customer-name{font-weight:600}
.customer-phone{font-size:11px;color:#94a3b8;margin-top:3px}
.badge{padding:5px 9px;border-radius:20px;font-size:11px;font-weight:600}
.badge-green{background:#dcfce7;color:#166534}
.badge-red{background:#fee2e2;color:#991b1b}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-yellow{background:#fef3c7;color:#92400e}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.action-btn{border:0;padding:7px 9px;border-radius:6px;cursor:pointer;font-size:11px}
.modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:2000;align-items:center;justify-content:center;padding:20px}
.modal-content{background:#fff;width:100%;max-width:650px;max-height:92vh;overflow-y:auto;border-radius:15px;padding:25px}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.modal-header h2{font-size:20px}
.close{border:0;background:none;font-size:24px;cursor:pointer}
.form-group{margin-bottom:16px}
.form-group label{display:block;font-size:12px;font-weight:600;margin-bottom:6px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
.invoice-info{background:#f8fafc;padding:15px;border-radius:10px;margin:15px 0}
.invoice-info div{display:flex;justify-content:space-between;padding:5px 0;font-size:13px}
.invoice-info .balance{font-size:17px;font-weight:800;color:#10b981;border-top:1px solid #e2e8f0;margin-top:8px;padding-top:12px}
@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr 1fr}}
@media(max-width:750px){.sidebar{width:70px;padding:20px 10px}.logo{font-size:0;text-align:center}.logo span{font-size:20px}.nav-link span{display:none}.nav-link{justify-content:center}.main{margin-left:70px;padding:20px}.summary-grid{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.user-details{display:none}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="logo">SME<span>360</span></div>
    <div class="nav-title">Main</div>
    <a href="dashboard.php" class="nav-link"><span>📊</span><span>Dashboard</span></a>
    <a href="sales.php" class="nav-link"><span>🛒</span><span>Sales</span></a>
    <a href="inventory.php" class="nav-link"><span>📦</span><span>Inventory</span></a>
    <div class="nav-title">Business</div>
    <a href="customers.php" class="nav-link"><span>👥</span><span>Customers</span></a>
    <a href="invoices.php" class="nav-link"><span>🧾</span><span>Invoices</span></a>
    <a href="payments.php" class="nav-link active"><span>💳</span><span>Payments</span></a>
    <a href="expenses.php" class="nav-link"><span>💰</span><span>Expenses</span></a>
    <div class="nav-title">System</div>
    <a href="reports.php" class="nav-link"><span>📈</span><span>Reports</span></a>
    <?php if ($role === 'Owner'): ?>
        <a href="users.php" class="nav-link"><span>👤</span><span>Users</span></a>
        <a href="settings.php" class="nav-link"><span>⚙️</span><span>Settings</span></a>
    <?php endif; ?>
    <a href="logout.php" class="nav-link"><span>🚪</span><span>Logout</span></a>
</aside>

<main class="main">
    <div class="topbar">
        <div class="page-title">
            <h1>Payments</h1>
            <p>Record and manage customer invoice payments.</p>
        </div>
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'U',0,1)); ?></div>
            <div class="user-details">
                <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></strong>
                <span><?= htmlspecialchars($role); ?></span>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert success"><?= htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="label">Total Received</div>
            <div class="value"><?= $currency_symbol . ' ' . number_format((float)$summary['total_received'],2); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Today's Payments</div>
            <div class="value"><?= $currency_symbol . ' ' . number_format((float)$summary['today_received'],2); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Completed Payments</div>
            <div class="value"><?= number_format((int)$summary['completed_payments']); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Reversed Payments</div>
            <div class="value"><?= number_format((int)$summary['reversed_payments']); ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Payment History</h2>
            <button class="btn btn-primary" onclick="openModal('paymentModal')">+ Record Payment</button>
        </div>

        <form method="GET" class="filters">
            <input type="text" name="search" placeholder="Search payment, invoice, customer..." value="<?= htmlspecialchars($search); ?>">
            <select name="payment_method">
                <option value="">All Methods</option>
                <option value="Cash" <?= $method_filter === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                <option value="Mobile Money" <?= $method_filter === 'Mobile Money' ? 'selected' : ''; ?>>Mobile Money</option>
                <option value="Bank Transfer" <?= $method_filter === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                <option value="Card" <?= $method_filter === 'Card' ? 'selected' : ''; ?>>Card</option>
            </select>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="reversed" <?= $status_filter === 'reversed' ? 'selected' : ''; ?>>Reversed</option>
            </select>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from); ?>">
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to); ?>">
            <button class="btn btn-secondary" type="submit">Filter</button>
        </form>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Payment</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$payments): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px;color:#64748b">
                            No payments found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $payment): ?>
                        <?php
                        $status_class = $payment['status'] === 'completed'
                            ? 'badge-green'
                            : 'badge-red';
                        ?>
                        <tr>
                            <td>
                                <div class="payment-id"><?= htmlspecialchars($payment['payment_id']); ?></div>
                            </td>
                            <td>
                                <a href="view-invoice.php?id=<?= urlencode($payment['invoice_id']); ?>" style="color:#2563eb;font-weight:600;text-decoration:none">
                                    <?= htmlspecialchars($payment['invoice_number']); ?>
                                </a>
                            </td>
                            <td>
                                <div class="customer-name"><?= htmlspecialchars($payment['customer_name']); ?></div>
                                <div class="customer-phone"><?= htmlspecialchars($payment['phone'] ?? ''); ?></div>
                            </td>
                            <td><?= date('d M Y', strtotime($payment['payment_date'])); ?></td>
                            <td><strong><?= $currency_symbol . ' ' . number_format((float)$payment['amount'],2); ?></strong></td>
                            <td><?= htmlspecialchars($payment['payment_method']); ?></td>
                            <td><?= htmlspecialchars($payment['reference'] ?? '-'); ?></td>
                            <td>
                                <span class="badge <?= $status_class; ?>">
                                    <?= ucfirst($payment['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($payment['status'] === 'completed'): ?>
                                    <form method="POST" onsubmit="return confirm('Reverse this payment? This will increase the invoice balance.');">
                                        <input type="hidden" name="action" value="reverse_payment">
                                        <input type="hidden" name="payment_id" value="<?= htmlspecialchars($payment['payment_id']); ?>">
                                        <button type="submit" class="action-btn btn-danger">Reverse</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:11px">Reversed</span>
                                <?php endif; ?>
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
            <h2>Outstanding Invoices</h2>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Outstanding</th>
                        <th>Due Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$outstanding_invoices): ?>
                    <tr>
                        <td colspan="7" style="text-align:center;padding:40px;color:#64748b">
                            No outstanding invoices.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($outstanding_invoices as $invoice): ?>
                        <tr>
                            <td>
                                <a href="view-invoice.php?id=<?= urlencode($invoice['invoice_id']); ?>" style="color:#2563eb;font-weight:600;text-decoration:none">
                                    <?= htmlspecialchars($invoice['invoice_number']); ?>
                                </a>
                            </td>
                            <td>
                                <div class="customer-name"><?= htmlspecialchars($invoice['customer_name']); ?></div>
                                <div class="customer-phone"><?= htmlspecialchars($invoice['phone'] ?? ''); ?></div>
                            </td>
                            <td><?= $currency_symbol . ' ' . number_format((float)$invoice['total_amount'],2); ?></td>
                            <td><?= $currency_symbol . ' ' . number_format((float)$invoice['amount_paid'],2); ?></td>
                            <td><strong><?= $currency_symbol . ' ' . number_format((float)$invoice['balance'],2); ?></strong></td>
                            <td>
                                <?= $invoice['due_date']
                                    ? date('d M Y', strtotime($invoice['due_date']))
                                    : '-'; ?>
                            </td>
                            <td>
                                <button
                                    class="action-btn btn-primary"
                                    onclick='openPaymentForInvoice(<?= json_encode($invoice); ?>)'>
                                    Pay
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div class="modal" id="paymentModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Record Payment</h2>
            <button class="close" onclick="closeModal('paymentModal')">×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="record_payment">

            <div class="form-group">
                <label>Invoice *</label>
                <select name="invoice_id" id="invoiceSelect" onchange="showInvoiceInfo()" required>
                    <option value="">Select Invoice</option>
                    <?php foreach ($outstanding_invoices as $invoice): ?>
                        <option
                            value="<?= htmlspecialchars($invoice['invoice_id']); ?>"
                            data-number="<?= htmlspecialchars($invoice['invoice_number']); ?>"
                            data-total="<?= htmlspecialchars($invoice['total_amount']); ?>"
                            data-paid="<?= htmlspecialchars($invoice['amount_paid']); ?>"
                            data-balance="<?= htmlspecialchars($invoice['balance']); ?>"
                            data-customer="<?= htmlspecialchars($invoice['customer_name']); ?>">
                            <?= htmlspecialchars($invoice['invoice_number']); ?> -
                            <?= htmlspecialchars($invoice['customer_name']); ?> -
                            <?= $currency_symbol . ' ' . number_format((float)$invoice['balance'],2); ?> outstanding
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="invoice-info" id="invoiceInfo" style="display:none">
                <div>
                    <span>Invoice</span>
                    <strong id="infoInvoice"></strong>
                </div>
                <div>
                    <span>Customer</span>
                    <strong id="infoCustomer"></strong>
                </div>
                <div>
                    <span>Total</span>
                    <strong id="infoTotal"></strong>
                </div>
                <div>
                    <span>Already Paid</span>
                    <strong id="infoPaid"></strong>
                </div>
                <div class="balance">
                    <span>Outstanding Balance</span>
                    <strong id="infoBalance"></strong>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Payment Date *</label>
                    <input type="date" name="payment_date" value="<?= date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label>Amount *</label>
                    <input type="number" name="amount" id="paymentAmount" min="0.01" step="0.01" required>
                </div>

                <div class="form-group">
                    <label>Payment Method *</label>
                    <select name="payment_method" required>
                        <option value="">Select Method</option>
                        <option value="Cash">Cash</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Card">Card</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Reference</label>
                    <input type="text" name="reference" placeholder="Transaction/reference number">
                </div>
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" rows="3" placeholder="Optional payment notes"></textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Record Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id){
    document.getElementById(id).style.display='flex';
}

function closeModal(id){
    document.getElementById(id).style.display='none';
}

function showInvoiceInfo(){
    const select=document.getElementById('invoiceSelect');
    const option=select.options[select.selectedIndex];
    const info=document.getElementById('invoiceInfo');

    if(!option.value){
        info.style.display='none';
        return;
    }

    info.style.display='block';

    document.getElementById('infoInvoice').textContent=option.dataset.number;
    document.getElementById('infoCustomer').textContent=option.dataset.customer;
    document.getElementById('infoTotal').textContent='<?= $currency_symbol; ?> '+parseFloat(option.dataset.total).toFixed(2);
    document.getElementById('infoPaid').textContent='<?= $currency_symbol; ?> '+parseFloat(option.dataset.paid).toFixed(2);
    document.getElementById('infoBalance').textContent='<?= $currency_symbol; ?> '+parseFloat(option.dataset.balance).toFixed(2);

    document.getElementById('paymentAmount').max=option.dataset.balance;
    document.getElementById('paymentAmount').value='';
}

function openPaymentForInvoice(invoice){
    openModal('paymentModal');

    const select=document.getElementById('invoiceSelect');

    select.value=invoice.invoice_id;

    showInvoiceInfo();

    document.getElementById('paymentAmount').max=invoice.balance;
    document.getElementById('paymentAmount').value='';
}

window.addEventListener('click',function(e){
    document.querySelectorAll('.modal').forEach(function(modal){
        if(e.target===modal){
            modal.style.display='none';
        }
    });
});
</script>
</body>
</html>
```
