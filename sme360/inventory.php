<?php
session_start();
require_once "db.php";

if (!isset($_SESSION['user_id']) || !isset($_SESSION['business_id'])) {
    header("Location: login.php");
    exit;
}

$user_id     = $_SESSION['user_id'];
$business_id = $_SESSION['business_id'];
$role        = $_SESSION['role'] ?? 'Staff';

function generateProductId(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT product_id FROM products WHERE product_id LIKE 'PROD%' ORDER BY CAST(SUBSTRING(product_id, 5) AS UNSIGNED) DESC LIMIT 1");
    $last_id = $stmt->fetchColumn();
    if (!$last_id) return "PROD0001";
    $number = (int) substr($last_id, 4);
    return "PROD" . str_pad($number + 1, 4, "0", STR_PAD_LEFT);
}

/* Generate Inventory Transaction ID, e.g. INVTR0001 */
function generateInventoryTransactionId(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT inventory_transaction_id FROM inventory_transactions WHERE inventory_transaction_id LIKE 'INVTR%' ORDER BY CAST(SUBSTRING(inventory_transaction_id, 6) AS UNSIGNED) DESC LIMIT 1");
    $last_id = $stmt->fetchColumn();
    if (!$last_id) return "INVTR0001";
    $number = (int) substr($last_id, 5);
    return "INVTR" . str_pad($number + 1, 4, "0", STR_PAD_LEFT);
}

/* Create Audit Log */
function createAuditLog(PDO $pdo, string $business_id, ?string $user_id, string $action, string $table_name, string $record_id, string $description): void
{
    $stmt = $pdo->prepare("INSERT INTO audit_logs (business_id, user_id, action, table_name, record_id, description) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$business_id, $user_id, $action, $table_name, $record_id, $description]);
}

/* ===== MESSAGE VARIABLES ===== */
$success = "";
$error   = "";

/* ===== ADD NEW PRODUCT ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'add_product') {

    $product_name         = trim($_POST['product_name'] ?? '');
    $category_id          = trim($_POST['category_id'] ?? '');
    $custom_category_name = trim($_POST['custom_category_name'] ?? '');
    $description          = trim($_POST['description'] ?? '');
    $product_type         = $_POST['product_type'] ?? 'product';
    $cost_price           = (float) ($_POST['cost_price'] ?? 0);
    $selling_price        = (float) ($_POST['selling_price'] ?? 0);
    $current_stock        = (float) ($_POST['current_stock'] ?? 0);
    $minimum_stock        = (float) ($_POST['minimum_stock'] ?? 0);

    if ($product_name === '') {
        $error = "Product name is required.";
    } elseif (!in_array($product_type, ['product', 'service'], true)) {
        $error = "Invalid product type.";
    } elseif ($cost_price < 0 || $selling_price < 0) {
        $error = "Prices cannot be negative.";
    } elseif ($current_stock < 0 || $minimum_stock < 0) {
        $error = "Stock quantities cannot be negative.";
    }

    /* Services do not require stock management */
    if ($product_type === 'service') {
        $current_stock = 0;
        $minimum_stock = 0;
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            /* Verify Category */
            if ($category_id !== '') {
                $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_id = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$category_id]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception("Selected category does not exist.");
                }
            } else {
                $category_id = null;
            }

            /* Generate Product ID */
            $product_id = generateProductId($pdo);

            /* Insert Product */
            $stmt = $pdo->prepare("
                INSERT INTO products
                (product_id, business_id, category_id, custom_category_name, product_name, description, product_type, cost_price, selling_price, current_stock, minimum_stock, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([
                $product_id, $business_id, $category_id,
                $custom_category_name !== '' ? $custom_category_name : null,
                $product_name, $description !== '' ? $description : null,
                $product_type, $cost_price, $selling_price, $current_stock, $minimum_stock
            ]);

            /* Record Initial Stock */
            if ($product_type === 'product' && $current_stock > 0) {
                $transaction_id = generateInventoryTransactionId($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_transactions
                    (inventory_transaction_id, business_id, product_id, user_id, transaction_type, quantity, reason, reference_type, reference_id)
                    VALUES (?, ?, ?, ?, 'stock_in', ?, ?, 'product_creation', ?)
                ");
                $stmt->execute([$transaction_id, $business_id, $product_id, $user_id, $current_stock, 'Initial stock', $product_id]);
            }

            /* Audit Log */
            createAuditLog($pdo, $business_id, $user_id, 'Product Created', 'products', $product_id, "Created product: {$product_name}");

            $pdo->commit();
            $success = "Product added successfully.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

/* ===== ADD STOCK / STOCK MOVEMENT ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'stock_movement') {

    $product_id       = trim($_POST['product_id'] ?? '');
    $transaction_type = $_POST['transaction_type'] ?? '';
    $quantity         = (float) ($_POST['quantity'] ?? 0);
    $reason           = trim($_POST['reason'] ?? '');

    $allowed_transactions = ['stock_in', 'stock_out', 'return', 'damage', 'adjustment'];

    if ($product_id === '') {
        $error = "Product is required.";
    } elseif (!in_array($transaction_type, $allowed_transactions, true)) {
        $error = "Invalid stock transaction.";
    } elseif ($quantity <= 0) {
        $error = "Quantity must be greater than zero.";
    }

    if ($error === '') {
        try {
            $pdo->beginTransaction();

            /* Lock Product Row */
            $stmt = $pdo->prepare("
                SELECT product_name, product_type, current_stock
                FROM products
                WHERE product_id = ? AND business_id = ? AND status = 'active'
                FOR UPDATE
            ");
            $stmt->execute([$product_id, $business_id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception("Product not found.");
            }
            if ($product['product_type'] === 'service') {
                throw new Exception("Services do not have inventory.");
            }

            $old_stock = (float) $product['current_stock'];
            $new_stock = $old_stock;

            /* Calculate New Stock */
            switch ($transaction_type) {

                case 'stock_in':
                case 'return':
                    $new_stock = $old_stock + $quantity;
                    break;

                case 'stock_out':
                case 'damage':
                    if ($quantity > $old_stock) {
                        throw new Exception("Insufficient stock. Available stock: " . number_format($old_stock, 3));
                    }
                    $new_stock = $old_stock - $quantity;
                    break;

                case 'adjustment':
                    /* For adjustment, quantity represents the NEW stock quantity. */
                    $new_stock = $quantity;
                    break;
            }

            /* Update Product Stock */
            $stmt = $pdo->prepare("UPDATE products SET current_stock = ? WHERE product_id = ? AND business_id = ?");
            $stmt->execute([$new_stock, $product_id, $business_id]);

            /* Record Transaction */
            $transaction_id = generateInventoryTransactionId($pdo);
            $stmt = $pdo->prepare("
                INSERT INTO inventory_transactions
                (inventory_transaction_id, business_id, product_id, user_id, transaction_type, quantity, reason, reference_type, reference_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'manual', ?)
            ");
            $stmt->execute([$transaction_id, $business_id, $product_id, $user_id, $transaction_type, $quantity, $reason !== '' ? $reason : null, $transaction_id]);

            /* Audit Log */
            createAuditLog($pdo, $business_id, $user_id, 'Inventory Updated', 'products', $product_id, "Stock movement: {$transaction_type}. Old stock: {$old_stock}. New stock: {$new_stock}.");

            $pdo->commit();
            $success = "Inventory updated successfully.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

/* ===== DEACTIVATE PRODUCT ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST['action'] ?? '') === 'delete_product') {

    $product_id = trim($_POST['product_id'] ?? '');

    if ($product_id === '') {
        $error = "Invalid product.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE products SET status = 'inactive' WHERE product_id = ? AND business_id = ?");
            $stmt->execute([$product_id, $business_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception("Product could not be found.");
            }

            createAuditLog($pdo, $business_id, $user_id, 'Product Deactivated', 'products', $product_id, "Product {$product_id} was deactivated.");
            $success = "Product removed from inventory.";

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

/* ===== FETCH CATEGORIES ===== */
$stmt = $pdo->query("SELECT category_id, category_name FROM categories WHERE status = 'active' ORDER BY category_name ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== SEARCH & FILTERS ===== */
$search          = trim($_GET['search'] ?? '');
$category_filter = trim($_GET['category'] ?? '');
$stock_filter    = trim($_GET['stock'] ?? '');

$where  = ["p.business_id = ?", "p.status = 'active'"];
$params = [$business_id];

if ($search !== '') {
    $where[] = "(p.product_name LIKE ? OR p.product_id LIKE ? OR p.custom_category_name LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($category_filter !== '') {
    $where[] = "p.category_id = ?";
    $params[] = $category_filter;
}

if ($stock_filter === 'out') {
    $where[] = "p.product_type = 'product' AND p.current_stock <= 0";
} elseif ($stock_filter === 'low') {
    $where[] = "p.product_type = 'product' AND p.current_stock > 0 AND p.current_stock <= p.minimum_stock";
} elseif ($stock_filter === 'in_stock') {
    $where[] = "p.product_type = 'product' AND p.current_stock > p.minimum_stock";
}

$whereSQL = implode(" AND ", $where);

/* ===== FETCH INVENTORY ===== */
$sql = "
    SELECT p.product_id, p.product_name, p.description, p.product_type, p.cost_price, p.selling_price,
           p.current_stock, p.minimum_stock, p.custom_category_name, p.category_id, c.category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    WHERE {$whereSQL}
    ORDER BY p.product_name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== INVENTORY SUMMARY ===== */
$stmt = $pdo->prepare("
    SELECT
        COUNT(CASE WHEN product_type = 'product' THEN 1 END) AS total_products,
        COALESCE(SUM(CASE WHEN product_type = 'product' THEN current_stock ELSE 0 END), 0) AS total_stock,
        COUNT(CASE WHEN product_type = 'product' AND current_stock <= 0 THEN 1 END) AS out_of_stock,
        COUNT(CASE WHEN product_type = 'product' AND current_stock > 0 AND current_stock <= minimum_stock THEN 1 END) AS low_stock,
        COALESCE(SUM(CASE WHEN product_type = 'product' THEN current_stock * cost_price ELSE 0 END), 0) AS inventory_value
    FROM products
    WHERE business_id = ? AND status = 'active'
");
$stmt->execute([$business_id]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

/* ===== RECENT INVENTORY TRANSACTIONS ===== */
$stmt = $pdo->prepare("
    SELECT i.inventory_transaction_id, i.transaction_type, i.quantity, i.reason, i.created_at, p.product_name, u.full_name
    FROM inventory_transactions i
    INNER JOIN products p ON i.product_id = p.product_id
    LEFT JOIN users u ON i.user_id = u.user_id
    WHERE i.business_id = ?
    ORDER BY i.created_at DESC
    LIMIT 10
");
$stmt->execute([$business_id]);
$recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===== CURRENCY ===== */
$currency_symbol = 'K';
try {
    $stmt = $pdo->prepare("SELECT currency_symbol FROM business_settings WHERE business_id = ? LIMIT 1");
    $stmt->execute([$business_id]);
    $currency = $stmt->fetchColumn();
    if ($currency) $currency_symbol = $currency;
} catch (Exception $e) {
    $currency_symbol = 'K';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inventory | SME360</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Inter', sans-serif; background: #f5f7fb; color: #172033; }
button, input, select, textarea { font-family: inherit; }
.sidebar { position: fixed; top: 0; left: 0; width: 245px; height: 100vh; background: #111827; color: white; padding: 25px 18px; z-index: 1000; }
.logo { font-size: 25px; font-weight: 800; margin-bottom: 35px; padding-left: 12px; }
.logo span { color: #10b981; }
.nav-title { font-size: 11px; color: #94a3b8; text-transform: uppercase; margin: 20px 12px 10px; letter-spacing: 1px; }
.nav-link { display: flex; align-items: center; gap: 12px; text-decoration: none; color: #cbd5e1; padding: 12px; border-radius: 9px; margin-bottom: 5px; font-size: 14px; }
.nav-link:hover, .nav-link.active { background: #1f2937; color: #10b981; }
.main { margin-left: 245px; padding: 30px; }
.topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
.page-title h1 { font-size: 28px; font-weight: 800; }
.page-title p { color: #64748b; font-size: 14px; margin-top: 5px; }
.user-info { display: flex; align-items: center; gap: 12px; }
.user-avatar { width: 42px; height: 42px; background: #10b981; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.user-details strong { display: block; font-size: 14px; }
.user-details span { color: #64748b; font-size: 12px; }
.alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
.alert.success { background: #dcfce7; color: #166534; }
.alert.error { background: #fee2e2; color: #991b1b; }
.summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 25px; }
.summary-card { background: white; padding: 22px; border-radius: 14px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
.summary-card .label { color: #64748b; font-size: 13px; margin-bottom: 10px; }
.summary-card .value { font-size: 25px; font-weight: 800; }
.summary-card .sub { font-size: 12px; color: #94a3b8; margin-top: 6px; }
.card { background: white; border-radius: 14px; padding: 22px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.04); }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.card-header h2 { font-size: 18px; }
.btn { border: none; padding: 10px 16px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; }
.btn-primary { background: #10b981; color: white; }
.btn-primary:hover { background: #059669; }
.btn-secondary { background: #e2e8f0; color: #334155; }
.btn-danger { background: #fee2e2; color: #b91c1c; }
.btn-warning { background: #fef3c7; color: #92400e; }
.filters { display: grid; grid-template-columns: 2fr 1fr 1fr auto; gap: 12px; margin-bottom: 20px; }
input, select, textarea { width: 100%; border: 1px solid #dbe2ea; padding: 11px 13px; border-radius: 8px; outline: none; background: white; font-size: 13px; }
input:focus, select:focus, textarea:focus { border-color: #10b981; }
.table-container { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; background: #f8fafc; color: #64748b; font-size: 11px; text-transform: uppercase; padding: 13px; }
td { padding: 15px 13px; border-bottom: 1px solid #eef2f7; font-size: 13px; }
.product-name { font-weight: 700; }
.product-id { color: #94a3b8; font-size: 11px; margin-top: 3px; }
.badge { padding: 5px 9px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-green { background: #dcfce7; color: #166534; }
.badge-yellow { background: #fef3c7; color: #92400e; }
.badge-red { background: #fee2e2; color: #991b1b; }
.badge-blue { background: #dbeafe; color: #1d4ed8; }
.actions { display: flex; gap: 6px; }
.action-btn { border: none; padding: 7px 9px; border-radius: 6px; cursor: pointer; font-size: 11px; }
.modal { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.6); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
.modal-content { background: white; width: 100%; max-width: 600px; max-height: 90vh; overflow-y: auto; border-radius: 15px; padding: 25px; }
.modal-header { display: flex; justify-content: space-between; margin-bottom: 20px; }
.modal-header h2 { font-size: 20px; }
.close { border: none; background: none; font-size: 24px; cursor: pointer; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.form-group { margin-bottom: 15px; }
.form-group.full { grid-column: span 2; }
.form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; }
.form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 15px; }
.transaction-table { max-height: 400px; overflow-y: auto; }
@media(max-width: 1100px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .filters { grid-template-columns: 1fr 1fr; }
}
@media(max-width: 750px) {
    .sidebar { width: 70px; padding: 20px 10px; }
    .logo { font-size: 0; text-align: center; }
    .logo span { font-size: 20px; }
    .nav-link span { display: none; }
    .nav-link { justify-content: center; }
    .main { margin-left: 70px; padding: 20px; }
    .summary-grid { grid-template-columns: 1fr; }
    .filters { grid-template-columns: 1fr; }
    .form-grid { grid-template-columns: 1fr; }
    .form-group.full { grid-column: span 1; }
    .user-details { display: none; }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="logo">SME<span>360</span></div>

    <div class="nav-title">Main</div>
    <a href="dashboard.php" class="nav-link"><span>📊</span><span>Dashboard</span></a>
    <a href="inventory.php" class="nav-link active"><span>📦</span><span>Inventory</span></a>

</aside>

<!-- MAIN CONTENT -->
<main class="main">

<!-- TOP BAR -->
<div class="topbar">
    <div class="page-title">
        <h1>Inventory</h1>
        <p>Manage your products, stock levels and inventory movements.</p>
    </div>

    <div class="user-info">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?></div>
        <div class="user-details">
            <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></strong>
            <span><?= htmlspecialchars($role); ?></span>
        </div>
    </div>
</div>

<!-- ALERTS -->
<?php if ($success !== ''): ?>
<div class="alert success"><?= htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
<div class="alert error"><?= htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- SUMMARY CARDS -->
<div class="summary-grid">

    <div class="summary-card">
        <div class="label">Total Products</div>
        <div class="value"><?= number_format((int)($summary['total_products'] ?? 0)); ?></div>
        <div class="sub">Active physical products</div>
    </div>

    <div class="summary-card">
        <div class="label">Total Stock</div>
        <div class="value"><?= number_format((float)($summary['total_stock'] ?? 0), 3); ?></div>
        <div class="sub">Units currently available</div>
    </div>

    <div class="summary-card">
        <div class="label">Low / Out of Stock</div>
        <div class="value">
            <?= number_format((int)($summary['low_stock'] ?? 0) + (int)($summary['out_of_stock'] ?? 0)); ?>
        </div>
        <div class="sub">
            <?= (int)($summary['low_stock'] ?? 0); ?> low stock ·
            <?= (int)($summary['out_of_stock'] ?? 0); ?> out of stock
        </div>
    </div>

    <div class="summary-card">
        <div class="label">Inventory Value</div>
        <div class="value">
            <?= htmlspecialchars($currency_symbol); ?>
            <?= number_format((float)($summary['inventory_value'] ?? 0), 2); ?>
        </div>
        <div class="sub">Based on cost price</div>
    </div>

</div>

<!-- INVENTORY TABLE -->
<div class="card">

    <div class="card-header">
        <h2>Products & Stock</h2>
        <button class="btn btn-primary" onclick="openModal('addProductModal')">+ Add Product</button>
    </div>

    <!-- FILTERS -->
    <form method="GET" class="filters">

        <input type="text" name="search" placeholder="Search product..." value="<?= htmlspecialchars($search); ?>">

        <select name="category">
            <option value="">All Categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= htmlspecialchars($category['category_id']); ?>" <?= $category_filter === $category['category_id'] ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($category['category_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="stock">
            <option value="">All Stock Status</option>
            <option value="in_stock" <?= $stock_filter === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
            <option value="low" <?= $stock_filter === 'low' ? 'selected' : ''; ?>>Low Stock</option>
            <option value="out" <?= $stock_filter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
        </select>

        <button type="submit" class="btn btn-secondary">Filter</button>

    </form>

    <!-- TABLE -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Cost Price</th>
                    <th>Selling Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($products)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:40px; color:#64748b;">No products found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    if ($product['product_type'] === 'service') {
                        $stock_status = 'Service';
                        $badge_class = 'badge-blue';
                    } elseif ((float)$product['current_stock'] <= 0) {
                        $stock_status = 'Out of Stock';
                        $badge_class = 'badge-red';
                    } elseif ((float)$product['current_stock'] <= (float)$product['minimum_stock']) {
                        $stock_status = 'Low Stock';
                        $badge_class = 'badge-yellow';
                    } else {
                        $stock_status = 'In Stock';
                        $badge_class = 'badge-green';
                    }

                    $category_name = $product['category_name'] ?? $product['custom_category_name'] ?? 'Uncategorized';
                    ?>
                    <tr>
                        <td>
                            <div class="product-name"><?= htmlspecialchars($product['product_name']); ?></div>
                            <div class="product-id"><?= htmlspecialchars($product['product_id']); ?></div>
                        </td>

                        <td><?= htmlspecialchars($category_name); ?></td>

                        <td><?= ucfirst(htmlspecialchars($product['product_type'])); ?></td>

                        <td><?= htmlspecialchars($currency_symbol); ?> <?= number_format((float)$product['cost_price'], 2); ?></td>

                        <td><?= htmlspecialchars($currency_symbol); ?> <?= number_format((float)$product['selling_price'], 2); ?></td>

                        <td>
                            <?php if ($product['product_type'] === 'service'): ?>
                                —
                            <?php else: ?>
                                <strong><?= number_format((float)$product['current_stock'], 3); ?></strong>
                                <small style="color:#94a3b8; display:block;">Min: <?= number_format((float)$product['minimum_stock'], 3); ?></small>
                            <?php endif; ?>
                        </td>

                        <td><span class="badge <?= $badge_class; ?>"><?= $stock_status; ?></span></td>

                        <td>
                            <div class="actions">
                                <?php if ($product['product_type'] === 'product'): ?>
                                <button class="action-btn btn-primary" onclick="openStockModal('<?= htmlspecialchars($product['product_id'], ENT_QUOTES); ?>', '<?= htmlspecialchars($product['product_name'], ENT_QUOTES); ?>', '<?= $product['current_stock']; ?>')">Stock</button>
                                <?php endif; ?>

                                <form method="POST" onsubmit="return confirm('Remove this product from active inventory?');">
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="product_id" value="<?= htmlspecialchars($product['product_id']); ?>">
                                    <button type="submit" class="action-btn btn-danger">Remove</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- RECENT INVENTORY MOVEMENTS -->
<div class="card">

    <div class="card-header">
        <h2>Recent Stock Movements</h2>
    </div>

    <div class="transaction-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Product</th>
                    <th>Movement</th>
                    <th>Quantity</th>
                    <th>Reason</th>
                    <th>Processed By</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($recent_transactions)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding:30px; color:#64748b;">No inventory transactions yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($recent_transactions as $transaction): ?>
                <tr>
                    <td><?= date('d M Y H:i', strtotime($transaction['created_at'])); ?></td>
                    <td><strong><?= htmlspecialchars($transaction['product_name']); ?></strong></td>
                    <td><span class="badge badge-blue"><?= ucwords(str_replace('_', ' ', $transaction['transaction_type'])); ?></span></td>
                    <td><?= number_format((float)$transaction['quantity'], 3); ?></td>
                    <td><?= htmlspecialchars($transaction['reason'] ?? '—'); ?></td>
                    <td><?= htmlspecialchars($transaction['full_name'] ?? 'System'); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</main>

<!-- ADD PRODUCT MODAL -->
<div class="modal" id="addProductModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Product</h2>
            <button class="close" onclick="closeModal('addProductModal')">×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="add_product">

            <div class="form-grid">

                <div class="form-group full">
                    <label>Product Name *</label>
                    <input type="text" name="product_name" required>
                </div>

                <div class="form-group">
                    <label>Product Type *</label>
                    <select name="product_type" id="productType" onchange="toggleStockFields()">
                        <option value="product">Physical Product</option>
                        <option value="service">Service</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= htmlspecialchars($category['category_id']); ?>"><?= htmlspecialchars($category['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Cost Price</label>
                    <input type="number" name="cost_price" min="0" step="0.01" value="0">
                </div>

                <div class="form-group">
                    <label>Selling Price</label>
                    <input type="number" name="selling_price" min="0" step="0.01" value="0">
                </div>

                <div class="form-group stock-field">
                    <label>Opening Stock</label>
                    <input type="number" name="current_stock" min="0" step="0.001" value="0">
                </div>

                <div class="form-group stock-field">
                    <label>Minimum Stock Level</label>
                    <input type="number" name="minimum_stock" min="0" step="0.001" value="0">
                </div>

                <div class="form-group full">
                    <label>Custom Category Name</label>
                    <input type="text" name="custom_category_name" placeholder="Optional">
                </div>

                <div class="form-group full">
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                </div>

            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addProductModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Product</button>
            </div>
        </form>
    </div>
</div>

<!-- STOCK MOVEMENT MODAL -->
<div class="modal" id="stockModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Manage Stock</h2>
            <button class="close" onclick="closeModal('stockModal')">×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="stock_movement">
            <input type="hidden" name="product_id" id="stockProductId">

            <div class="form-group">
                <label>Product</label>
                <input type="text" id="stockProductName" readonly>
            </div>

            <div class="form-group">
                <label>Current Stock</label>
                <input type="text" id="stockCurrent" readonly>
            </div>

            <div class="form-group">
                <label>Transaction Type *</label>
                <select name="transaction_type" required>
                    <option value="stock_in">Stock In</option>
                    <option value="stock_out">Stock Out</option>
                    <option value="return">Customer Return</option>
                    <option value="damage">Damaged Stock</option>
                    <option value="adjustment">Stock Adjustment</option>
                </select>
            </div>

            <div class="form-group">
                <label>Quantity *</label>
                <input type="number" name="quantity" min="0.001" step="0.001" required>
                <small style="color:#64748b;">For Stock Adjustment, enter the new total stock quantity.</small>
            </div>

            <div class="form-group">
                <label>Reason</label>
                <textarea name="reason" rows="3" placeholder="Enter reason for this stock movement..."></textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('stockModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- JAVASCRIPT -->
<script>

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function openStockModal(productId, productName, currentStock) {
    document.getElementById('stockProductId').value = productId;
    document.getElementById('stockProductName').value = productName;
    document.getElementById('stockCurrent').value = currentStock;
    openModal('stockModal');
}

function toggleStockFields() {
    const type = document.getElementById('productType').value;
    const fields = document.querySelectorAll('.stock-field');
    fields.forEach(function(field) {
        field.style.display = (type === 'service') ? 'none' : 'block';
    });
}

window.addEventListener('click', function(event) {
    document.querySelectorAll('.modal').forEach(function(modal) {
        if (event.target === modal) modal.style.display = 'none';
    });
});

document.addEventListener('DOMContentLoaded', function() {
    toggleStockFields();
});

</script>

</body>
</html>