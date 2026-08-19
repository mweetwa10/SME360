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

function generateInvoiceId(PDO $pdo): string {
    $stmt = $pdo->query("
        SELECT invoice_id FROM invoices
        WHERE invoice_id LIKE 'INV%'
        ORDER BY CAST(SUBSTRING(invoice_id, 4) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $last = $stmt->fetchColumn();
    $number = $last ? (int)substr($last, 3) + 1 : 1;
    return 'INV' . str_pad($number, 6, '0', STR_PAD_LEFT);
}

function generateInvoiceNumber(PDO $pdo, string $business_id): string {
    $stmt = $pdo->prepare("
        SELECT invoice_number FROM invoices
        WHERE business_id = ?
        ORDER BY CAST(SUBSTRING(invoice_number, 4) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $stmt->execute([$business_id]);
    $last = $stmt->fetchColumn();
    $number = $last ? (int)substr($last, 3) + 1 : 1;
    return 'INV' . str_pad($number, 6, '0', STR_PAD_LEFT);
}

function createAuditLog(PDO $pdo, string $business_id, ?string $user_id, string $action, string $table, string $record_id, string $description): void {
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs
        (business_id, user_id, action, table_name, record_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$business_id, $user_id, $action, $table, $record_id, $description]);
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_invoice') {
        $customer_id = trim($_POST['customer_id'] ?? '');
        $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? null;
        $discount = (float)($_POST['discount'] ?? 0);
        $tax = (float)($_POST['tax'] ?? 0);
        $amount_paid = (float)($_POST['amount_paid'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $product_ids = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unit_prices = $_POST['unit_price'] ?? [];
        $item_discounts = $_POST['item_discount'] ?? [];

        if ($customer_id === '') {
            $error = "Please select a customer.";
        } elseif (empty($product_ids)) {
            $error = "Please add at least one invoice item.";
        } elseif ($discount < 0 || $tax < 0 || $amount_paid < 0) {
            $error = "Invalid invoice amounts.";
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT customer_id FROM customers
                    WHERE customer_id = ? AND business_id = ? AND status = 'active'
                ");
                $stmt->execute([$customer_id, $business_id]);

                if (!$stmt->fetchColumn()) {
                    throw new Exception("Customer not found.");
                }

                $items = [];
                $subtotal = 0;

                foreach ($product_ids as $i => $product_id) {
                    $product_id = trim($product_id);
                    $quantity = (float)($quantities[$i] ?? 0);
                    $unit_price = (float)($unit_prices[$i] ?? 0);
                    $item_discount = (float)($item_discounts[$i] ?? 0);

                    if ($product_id === '' || $quantity <= 0 || $unit_price < 0 || $item_discount < 0) {
                        throw new Exception("One or more invoice items are invalid.");
                    }

                    $stmt = $pdo->prepare("
                        SELECT product_id, product_name, product_type
                        FROM products
                        WHERE product_id = ? AND business_id = ? AND status = 'active'
                    ");
                    $stmt->execute([$product_id, $business_id]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {
                        throw new Exception("One of the selected products was not found.");
                    }

                    $item_subtotal = ($quantity * $unit_price) - $item_discount;

                    if ($item_subtotal < 0) {
                        throw new Exception("Item discount cannot exceed item value.");
                    }

                    $subtotal += $item_subtotal;

                    $items[] = [
                        'product_id' => $product_id,
                        'quantity' => $quantity,
                        'unit_price' => $unit_price,
                        'discount' => $item_discount,
                        'subtotal' => $item_subtotal
                    ];
                }

                $total_amount = $subtotal - $discount + $tax;

                if ($total_amount < 0) {
                    throw new Exception("Invoice total cannot be negative.");
                }

                if ($amount_paid > $total_amount) {
                    throw new Exception("Amount paid cannot exceed invoice total.");
                }

                $balance = $total_amount - $amount_paid;

                if ($amount_paid >= $total_amount) {
                    $status = 'paid';
                } elseif ($amount_paid > 0) {
                    $status = 'partially_paid';
                } else {
                    $status = 'issued';
                }

                $invoice_id = generateInvoiceId($pdo);
                $invoice_number = generateInvoiceNumber($pdo, $business_id);

                $stmt = $pdo->prepare("
                    INSERT INTO invoices
                    (invoice_id, business_id, customer_id, user_id, invoice_number,
                     invoice_date, due_date, subtotal, discount, tax, total_amount,
                     amount_paid, balance, status, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $invoice_id,
                    $business_id,
                    $customer_id,
                    $user_id,
                    $invoice_number,
                    $invoice_date,
                    $due_date ?: null,
                    $subtotal,
                    $discount,
                    $tax,
                    $total_amount,
                    $amount_paid,
                    $balance,
                    $status,
                    $notes ?: null
                ]);

                $stmt = $pdo->prepare("
                    INSERT INTO invoice_items
                    (invoice_id, product_id, quantity, unit_price, discount, subtotal)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                foreach ($items as $item) {
                    $stmt->execute([
                        $invoice_id,
                        $item['product_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['discount'],
                        $item['subtotal']
                    ]);
                }

                createAuditLog(
                    $pdo,
                    $business_id,
                    $user_id,
                    'Invoice Created',
                    'invoices',
                    $invoice_id,
                    "Created invoice {$invoice_number}."
                );

                $pdo->commit();
                $success = "Invoice {$invoice_number} created successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            }
        }
    }

    if ($action === 'cancel_invoice') {
        $invoice_id = trim($_POST['invoice_id'] ?? '');

        try {
            $stmt = $pdo->prepare("
                UPDATE invoices
                SET status = 'cancelled'
                WHERE invoice_id = ?
                AND business_id = ?
                AND status NOT IN ('paid', 'cancelled')
            ");
            $stmt->execute([$invoice_id, $business_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Invoice cannot be cancelled.");
            }

            createAuditLog(
                $pdo,
                $business_id,
                $user_id,
                'Invoice Cancelled',
                'invoices',
                $invoice_id,
                "Cancelled invoice {$invoice_id}."
            );

            $success = "Invoice cancelled successfully.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = ["i.business_id = ?"];
$params = [$business_id];

if ($search !== '') {
    $where[] = "(i.invoice_number LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($status_filter !== '') {
    $where[] = "i.status = ?";
    $params[] = $status_filter;
}

if ($date_from !== '') {
    $where[] = "i.invoice_date >= ?";
    $params[] = $date_from;
}

if ($date_to !== '') {
    $where[] = "i.invoice_date <= ?";
    $params[] = $date_to;
}

$where_sql = implode(" AND ", $where);

$stmt = $pdo->prepare("
    SELECT
        i.invoice_id,
        i.invoice_number,
        i.invoice_date,
        i.due_date,
        i.subtotal,
        i.discount,
        i.tax,
        i.total_amount,
        i.amount_paid,
        i.balance,
        i.status,
        c.customer_name,
        c.phone,
        u.full_name
    FROM invoices i
    INNER JOIN customers c ON i.customer_id = c.customer_id
    LEFT JOIN users u ON i.user_id = u.user_id
    WHERE {$where_sql}
    ORDER BY i.created_at DESC
");
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_invoices,
        COALESCE(SUM(total_amount), 0) AS total_value,
        COALESCE(SUM(amount_paid), 0) AS total_paid,
        COALESCE(SUM(balance), 0) AS total_outstanding
    FROM invoices
    WHERE business_id = ?
    AND status != 'cancelled'
");
$stmt->execute([$business_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT customer_id, customer_name, phone
    FROM customers
    WHERE business_id = ? AND status = 'active'
    ORDER BY customer_name
");
$stmt->execute([$business_id]);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT product_id, product_name, product_type, selling_price, current_stock
    FROM products
    WHERE business_id = ? AND status = 'active'
    ORDER BY product_name
");
$stmt->execute([$business_id]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
<title>Invoices | SME360</title>
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
.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;margin-bottom:20px}
input,select,textarea{width:100%;border:1px solid #dbe2ea;padding:11px 13px;border-radius:8px;outline:0;background:#fff;font-size:13px}
input:focus,select:focus,textarea:focus{border-color:#10b981}
.table-container{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{text-align:left;background:#f8fafc;color:#64748b;font-size:11px;text-transform:uppercase;padding:13px}
td{padding:14px 13px;border-bottom:1px solid #eef2f7;font-size:13px}
.invoice-number{font-weight:700;color:#172033}
.customer-name{font-weight:600}
.customer-phone{font-size:11px;color:#94a3b8;margin-top:3px}
.badge{padding:5px 9px;border-radius:20px;font-size:11px;font-weight:600}
.badge-green{background:#dcfce7;color:#166534}
.badge-yellow{background:#fef3c7;color:#92400e}
.badge-red{background:#fee2e2;color:#991b1b}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.actions{display:flex;gap:6px;flex-wrap:wrap}
.action-btn{border:0;padding:7px 9px;border-radius:6px;cursor:pointer;font-size:11px}
.modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:2000;align-items:center;justify-content:center;padding:20px}
.modal-content{background:#fff;width:100%;max-width:850px;max-height:92vh;overflow-y:auto;border-radius:15px;padding:25px}
.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.modal-header h2{font-size:20px}
.close{border:0;background:none;font-size:24px;cursor:pointer}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.form-group{margin-bottom:15px}
.form-group.full{grid-column:span 2}
.form-group label{display:block;font-size:12px;font-weight:600;margin-bottom:6px}
.items-header{display:flex;justify-content:space-between;align-items:center;margin:20px 0 10px}
.items-header h3{font-size:15px}
.invoice-item{display:grid;grid-template-columns:2fr .8fr 1fr .8fr 40px;gap:8px;margin-bottom:8px;align-items:center}
.remove-item{height:38px;border:0;background:#fee2e2;color:#b91c1c;border-radius:7px;cursor:pointer}
.totals{margin:20px 0 0 auto;max-width:350px}
.total-row{display:flex;justify-content:space-between;padding:8px 0;font-size:13px}
.total-row.final{border-top:1px solid #e2e8f0;margin-top:8px;padding-top:14px;font-size:17px;font-weight:800}
.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
@media(max-width:1100px){.summary-grid{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr 1fr}}
@media(max-width:750px){.sidebar{width:70px;padding:20px 10px}.logo{font-size:0;text-align:center}.logo span{font-size:20px}.nav-link span{display:none}.nav-link{justify-content:center}.main{margin-left:70px;padding:20px}.summary-grid{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.form-group.full{grid-column:span 1}.invoice-item{grid-template-columns:1fr 1fr}.user-details{display:none}}
</style>
</head>
<body>

<aside class="sidebar">
    <div class="logo">SME<span>360</span></div>
    <div class="nav-title">Main</div>
    <a href="dashboard.php" class="nav-link"><span>📊</span><span>Dashboard</span></a>
    <a href="invoices.php" class="nav-link active"><span>🧾</span><span>Invoices</span></a>
</aside>

<main class="main">
    <div class="topbar">
        <div class="page-title">
            <h1>Invoices</h1>
            <p>Create, manage and track customer invoices.</p>
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
            <div class="label">Total Invoices</div>
            <div class="value"><?= number_format((int)$summary['total_invoices']); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Invoice Value</div>
            <div class="value"><?= $currency_symbol . ' ' . number_format((float)$summary['total_value'],2); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Amount Paid</div>
            <div class="value"><?= $currency_symbol . ' ' . number_format((float)$summary['total_paid'],2); ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Outstanding</div>
            <div class="value"><?= $currency_symbol . ' ' . number_format((float)$summary['total_outstanding'],2); ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Invoice Management</h2>
            <button class="btn btn-primary" onclick="openModal('invoiceModal')">+ Create Invoice</button>
        </div>

        <form method="GET" class="filters">
            <input type="text" name="search" placeholder="Search invoice, customer or phone..." value="<?= htmlspecialchars($search); ?>">
            <select name="status">
                <option value="">All Statuses</option>
                <option value="draft" <?= $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="issued" <?= $status_filter === 'issued' ? 'selected' : ''; ?>>Issued</option>
                <option value="partially_paid" <?= $status_filter === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                <option value="paid" <?= $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="overdue" <?= $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from); ?>">
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to); ?>">
            <button class="btn btn-secondary" type="submit">Filter</button>
        </form>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$invoices): ?>
                    <tr><td colspan="8" style="text-align:center;padding:40px;color:#64748b">No invoices found.</td></tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        $status_class = match($invoice['status']) {
                            'paid' => 'badge-green',
                            'partially_paid' => 'badge-yellow',
                            'cancelled' => 'badge-red',
                            'overdue' => 'badge-red',
                            default => 'badge-blue'
                        };
                        ?>
                        <tr>
                            <td>
                                <div class="invoice-number"><?= htmlspecialchars($invoice['invoice_number']); ?></div>
                                <small><?= htmlspecialchars($invoice['invoice_id']); ?></small>
                            </td>
                            <td>
                                <div class="customer-name"><?= htmlspecialchars($invoice['customer_name']); ?></div>
                                <div class="customer-phone"><?= htmlspecialchars($invoice['phone'] ?? ''); ?></div>
                            </td>
                            <td>
                                <?= date('d M Y', strtotime($invoice['invoice_date'])); ?>
                                <?php if ($invoice['due_date']): ?>
                                    <small style="display:block;color:#94a3b8">Due: <?= date('d M Y', strtotime($invoice['due_date'])); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= $currency_symbol . ' ' . number_format((float)$invoice['total_amount'],2); ?></td>
                            <td><?= $currency_symbol . ' ' . number_format((float)$invoice['amount_paid'],2); ?></td>
                            <td><?= $currency_symbol . ' ' . number_format((float)$invoice['balance'],2); ?></td>
                            <td>
                                <span class="badge <?= $status_class; ?>">
                                    <?= ucwords(str_replace('_',' ',$invoice['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <a href="view-invoice.php?id=<?= urlencode($invoice['invoice_id']); ?>" class="action-btn btn-secondary">View</a>
                                    <?php if (!in_array($invoice['status'], ['paid','cancelled'], true)): ?>
                                        <form method="POST" onsubmit="return confirm('Cancel this invoice?');">
                                            <input type="hidden" name="action" value="cancel_invoice">
                                            <input type="hidden" name="invoice_id" value="<?= htmlspecialchars($invoice['invoice_id']); ?>">
                                            <button class="action-btn btn-danger">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div class="modal" id="invoiceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Create Invoice</h2>
            <button class="close" onclick="closeModal('invoiceModal')">×</button>
        </div>

        <form method="POST" id="invoiceForm">
            <input type="hidden" name="action" value="create_invoice">

            <div class="form-grid">
                <div class="form-group">
                    <label>Customer *</label>
                    <select name="customer_id" required>
                        <option value="">Select Customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= htmlspecialchars($customer['customer_id']); ?>">
                                <?= htmlspecialchars($customer['customer_name']); ?><?= $customer['phone'] ? ' - ' . htmlspecialchars($customer['phone']) : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Invoice Date *</label>
                    <input type="date" name="invoice_date" value="<?= date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" name="due_date">
                </div>

                <div class="form-group">
                    <label>Amount Paid</label>
                    <input type="number" id="amountPaid" name="amount_paid" value="0" min="0" step="0.01" oninput="calculateTotals()">
                </div>
            </div>

            <div class="items-header">
                <h3>Invoice Items</h3>
                <button type="button" class="btn btn-secondary" onclick="addItem()">+ Add Item</button>
            </div>

            <div id="invoiceItems"></div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Invoice Discount</label>
                    <input type="number" id="invoiceDiscount" name="discount" value="0" min="0" step="0.01" oninput="calculateTotals()">
                </div>

                <div class="form-group">
                    <label>Tax</label>
                    <input type="number" id="invoiceTax" name="tax" value="0" min="0" step="0.01" oninput="calculateTotals()">
                </div>

                <div class="form-group full">
                    <label>Notes</label>
                    <textarea name="notes" rows="3" placeholder="Optional invoice notes"></textarea>
                </div>
            </div>

            <div class="totals">
                <div class="total-row"><span>Subtotal</span><strong id="subtotalDisplay">0.00</strong></div>
                <div class="total-row"><span>Discount</span><strong id="discountDisplay">0.00</strong></div>
                <div class="total-row"><span>Tax</span><strong id="taxDisplay">0.00</strong></div>
                <div class="total-row final"><span>Total</span><strong id="totalDisplay">0.00</strong></div>
                <div class="total-row"><span>Balance</span><strong id="balanceDisplay">0.00</strong></div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('invoiceModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Invoice</button>
            </div>
        </form>
    </div>
</div>

<script>
const products = <?= json_encode($products); ?>;
const currency = <?= json_encode($currency_symbol); ?>;

function openModal(id){document.getElementById(id).style.display='flex'}
function closeModal(id){document.getElementById(id).style.display='none'}

function addItem(){
    const container=document.getElementById('invoiceItems');
    const row=document.createElement('div');
    row.className='invoice-item';

    let options='<option value="">Select Product</option>';
    products.forEach(p=>{
        options+=`<option value="${p.product_id}" data-price="${p.selling_price}">${p.product_name} (${p.product_type})</option>`;
    });

    row.innerHTML=`
        <select name="product_id[]" onchange="setPrice(this)" required>${options}</select>
        <input type="number" name="quantity[]" value="1" min="0.001" step="0.001" placeholder="Qty" oninput="calculateTotals()" required>
        <input type="number" name="unit_price[]" value="0" min="0" step="0.01" placeholder="Price" oninput="calculateTotals()" required>
        <input type="number" name="item_discount[]" value="0" min="0" step="0.01" placeholder="Discount" oninput="calculateTotals()">
        <button type="button" class="remove-item" onclick="this.parentElement.remove();calculateTotals()">×</button>
    `;

    container.appendChild(row);
    calculateTotals();
}

function setPrice(select){
    const price=select.options[select.selectedIndex].dataset.price||0;
    select.parentElement.querySelector('[name="unit_price[]"]').value=price;
    calculateTotals();
}

function calculateTotals(){
    let subtotal=0;

    document.querySelectorAll('.invoice-item').forEach(row=>{
        const qty=parseFloat(row.querySelector('[name="quantity[]"]').value)||0;
        const price=parseFloat(row.querySelector('[name="unit_price[]"]').value)||0;
        const discount=parseFloat(row.querySelector('[name="item_discount[]"]').value)||0;
        subtotal+=(qty*price)-discount;
    });

    const discount=parseFloat(document.getElementById('invoiceDiscount').value)||0;
    const tax=parseFloat(document.getElementById('invoiceTax').value)||0;
    const paid=parseFloat(document.getElementById('amountPaid').value)||0;
    const total=Math.max(0,subtotal-discount+tax);
    const balance=Math.max(0,total-paid);

    document.getElementById('subtotalDisplay').textContent=currency+' '+subtotal.toFixed(2);
    document.getElementById('discountDisplay').textContent=currency+' '+discount.toFixed(2);
    document.getElementById('taxDisplay').textContent=currency+' '+tax.toFixed(2);
    document.getElementById('totalDisplay').textContent=currency+' '+total.toFixed(2);
    document.getElementById('balanceDisplay').textContent=currency+' '+balance.toFixed(2);
}

document.addEventListener('DOMContentLoaded',()=>addItem());

window.addEventListener('click',e=>{
    document.querySelectorAll('.modal').forEach(modal=>{
        if(e.target===modal)modal.style.display='none';
    });
});
</script>
</body>
</html>
```
