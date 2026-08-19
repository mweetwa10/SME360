<?php
require_once "auth_check.php";
require_once "db.php";

$business_id = $_SESSION["business_id"];

$message = "";
$error = "";

if (isset($_GET["updated"]) && $_GET["updated"] === "1") {
    $message = "Product updated successfully.";
}

function generateProductID($pdo)
{
    $prefix = "PRD";

    $query = $pdo->query("
        SELECT product_id
        FROM products
        ORDER BY product_id DESC
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

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_product"])) {

    $product_name = trim($_POST["product_name"] ?? "");
    $category_id = trim($_POST["category_id"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $product_type = $_POST["product_type"] ?? "product";
    $cost_price = $_POST["cost_price"] ?? 0;
    $selling_price = $_POST["selling_price"] ?? 0;
    $current_stock = $_POST["current_stock"] ?? 0;
    $minimum_stock = $_POST["minimum_stock"] ?? 0;

    if ($product_name === "") {
        $error = "Please enter the product or service name.";
    } elseif (!in_array($product_type, ["product", "service"])) {
        $error = "Invalid product type.";
    } elseif (!is_numeric($cost_price) || !is_numeric($selling_price)) {
        $error = "Please enter valid prices.";
    } else {

       
        if ($product_type === "service") {
            $current_stock = 0;
            $minimum_stock = 0;
        }

        
        if ($category_id !== "") {

            $categoryCheck = $pdo->prepare("
                SELECT category_id
                FROM categories
                WHERE category_id = :category_id
                AND status = 'active'
                LIMIT 1
            ");

            $categoryCheck->execute([
                ":category_id" => $category_id
            ]);

            if (!$categoryCheck->fetch()) {
                $error = "Invalid product category.";
            }
        }

        if ($error === "") {

            try {

                $product_id = generateProductID($pdo);

                $insert = $pdo->prepare("
                    INSERT INTO products (
                        product_id,
                        business_id,
                        category_id,
                        product_name,
                        description,
                        product_type,
                        cost_price,
                        selling_price,
                        current_stock,
                        minimum_stock,
                        status
                    )
                    VALUES (
                        :product_id,
                        :business_id,
                        :category_id,
                        :product_name,
                        :description,
                        :product_type,
                        :cost_price,
                        :selling_price,
                        :current_stock,
                        :minimum_stock,
                        'active'
                    )
                ");

                $insert->execute([
                    ":product_id" => $product_id,
                    ":business_id" => $business_id,
                    ":category_id" => $category_id !== "" ? $category_id : null,
                    ":product_name" => $product_name,
                    ":description" => $description !== "" ? $description : null,
                    ":product_type" => $product_type,
                    ":cost_price" => (float) $cost_price,
                    ":selling_price" => (float) $selling_price,
                    ":current_stock" => (float) $current_stock,
                    ":minimum_stock" => (float) $minimum_stock
                ]);

                $message = "Product added successfully.";

            } catch (PDOException $e) {
                $error = "Unable to add product. Please try again.";
            }
        }
    }
}

if (isset($_GET["toggle"]) && $_GET["toggle"] !== "") {

    $product_id = $_GET["toggle"];

    $toggleQuery = $pdo->prepare("
        UPDATE products
        SET status = CASE
            WHEN status = 'active' THEN 'inactive'
            ELSE 'active'
        END
        WHERE product_id = :product_id
        AND business_id = :business_id
    ");

    $toggleQuery->execute([
        ":product_id" => $product_id,
        ":business_id" => $business_id
    ]);

    header("Location: products.php");
    exit;
}

$categoryQuery = $pdo->prepare("
    SELECT category_id, category_name
    FROM categories
    WHERE status = 'active'
    ORDER BY category_name ASC
");

$categoryQuery->execute();

$categories = $categoryQuery->fetchAll();

$search = trim($_GET["search"] ?? "");

$filter_category = trim($_GET["category"] ?? "");

$sql = "
    SELECT
        p.product_id,
        p.product_name,
        p.description,
        p.product_type,
        p.cost_price,
        p.selling_price,
        p.current_stock,
        p.minimum_stock,
        p.status,
        p.category_id,
        p.custom_category_name,
        c.category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    WHERE p.business_id = :business_id
";

$params = [
    ":business_id" => $business_id
];

if ($search !== "") {

    $sql .= "
        AND (
            p.product_name LIKE :search
            OR p.product_id LIKE :search
            OR p.description LIKE :search
            OR p.custom_category_name LIKE :search
        )
    ";

    $params[":search"] = "%" . $search . "%";
}

if ($filter_category !== "") {

    $sql .= "
        AND p.category_id = :category_id
    ";

    $params[":category_id"] = $filter_category;
}

$sql .= "
    ORDER BY p.created_at DESC
";

$productQuery = $pdo->prepare($sql);
$productQuery->execute($params);
$products = $productQuery->fetchAll();

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
    <title>Products | SME360</title>

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
.filters {
    background: white;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    margin-bottom: 20px;
}

.filter-form {
    display: flex;
    gap: 10px;
}

.filter-form input {
    flex: 1;
}

.filter-form select {
    min-width: 180px;
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

.product-name {
    font-weight: 600;
    color: #111827;
}

.product-id {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 3px;
}

.type {
    padding: 5px 9px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 600;
}

.type-product {
    background: #dbeafe;
    color: #1d4ed8;
}

.type-service {
    background: #f3e8ff;
    color: #7e22ce;
}

.stock-low {
    color: #dc2626;
    font-weight: 700;
}

.stock-good {
    color: #16a34a;
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

.status-inactive {
    background: #fee2e2;
    color: #b91c1c;
}

.action-link {
    color: #2563eb;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
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
        flex-direction: column;
    }

    .filter-form select {
        min-width: 100%;
    }

    .filter-button {
        padding: 12px;
    }

    .clear-button {
        justify-content: center;
        padding: 10px;
    }
}
</style>

<script>
function toggleForm() {
    const form = document.getElementById("addProductForm");
    form.classList.toggle("show");
}

function toggleStockFields() {
    const type = document.getElementById("product_type").value;
    const stockFields = document.getElementById("stockFields");

    if (type === "service") {
        stockFields.style.display = "none";
    } else {
        stockFields.style.display = "grid";
    }
}
</script>
</head>

<body>
<aside class="sidebar">

    <div class="logo">SME<span>360</span></div>

    <div class="nav-title">Main Menu</div>

    <a href="dashboard.php" class="nav-link">
        <span>📊</span>
        <span>Dashboard</span>
    </a>

    <a href="products.php" class="nav-link active">
        <span>□</span>
        <span>Products</span>
    </a>


</aside>

<main class="main">

    <header class="topbar">

        <div>
            <h1>Products & Services</h1>
        </div>

        <div class="user">
            Welcome, <strong><?= htmlspecialchars($_SESSION["full_name"]) ?></strong>
        </div>

    </header>

    <div class="content">

        <div class="page-header">

            <div>
                <h2>Products & Services</h2>
                <p>Manage the products and services offered by your business.</p>
            </div>

            <button class="add-button" onclick="toggleForm()">+ Add Product</button>

        </div>

        <?php if ($message !== ""): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="form-card" id="addProductForm">

            <h3>Add Product or Service</h3>

            <br>

            <form method="POST" action="">

                <div class="form-grid">

                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" name="product_name" placeholder="e.g. Laptop, Haircut, 10kg Rice" required>
                    </div>

                    <div class="form-group">
                        <label>Type *</label>
                        <select name="product_type" id="product_type" onchange="toggleStockFields()">
                            <option value="product">Product</option>
                            <option value="service">Service</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id">
                            <option value="">No Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category["category_id"]) ?>">
                                    <?= htmlspecialchars($category["category_name"]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Cost Price</label>
                        <input type="number" name="cost_price" min="0" step="0.01" value="0" required>
                    </div>

                    <div class="form-group">
                        <label>Selling Price *</label>
                        <input type="number" name="selling_price" min="0" step="0.01" value="0" required>
                    </div>

                    <div class="form-group" id="stockFields">
                        <label>Current Stock</label>
                        <input type="number" name="current_stock" min="0" step="0.001" value="0">
                    </div>

                    <div class="form-group" id="minimumStockField">
                        <label>Minimum Stock</label>
                        <input type="number" name="minimum_stock" min="0" step="0.001" value="0">
                    </div>

                    <div class="form-group full">
                        <label>Description</label>
                        <textarea name="description" placeholder="Optional description"></textarea>
                    </div>

                </div>

                <div class="form-actions">
                    <button type="submit" name="add_product" class="submit-button">Save Product</button>
                    <button type="button" class="cancel-button" onclick="toggleForm()">Cancel</button>
                </div>

            </form>

        </div>

        <div class="filters">

            <form method="GET" class="filter-form">

                <input type="text" name="search" placeholder="Search by product name, ID or description" value="<?= htmlspecialchars($search) ?>">

                <select name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= htmlspecialchars($category["category_id"]) ?>" <?= $filter_category === $category["category_id"] ? "selected" : "" ?>>
                            <?= htmlspecialchars($category["category_name"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="filter-button">Search</button>

                <a href="products.php" class="clear-button">Clear</a>

            </form>

        </div>
        
        <div class="table-card">

            <div class="table-wrapper">

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
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($products)): ?>

                        <tr>
                            <td colspan="8" class="empty">No products or services found.</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($products as $product): ?>

                            <tr>

                                <td>
                                    <div class="product-name"><?= htmlspecialchars($product["product_name"]) ?></div>
                                    <div class="product-id"><?= htmlspecialchars($product["product_id"]) ?></div>
                                </td>

                                <td>
                                    <?= htmlspecialchars($product["category_name"] ?? "Uncategorized") ?>
                                </td>

                                <td>
                                    <span class="type type-<?= $product["product_type"] ?>">
                                        <?= ucfirst($product["product_type"]) ?>
                                    </span>
                                </td>

                                <td><?= formatMoney($product["cost_price"]) ?></td>

                                <td><strong><?= formatMoney($product["selling_price"]) ?></strong></td>

                                <td>
                                    <?php if ($product["product_type"] === "service"): ?>

                                        <span style="color:#9ca3af;">N/A</span>

                                    <?php else: ?>

                                        <span class="<?= $product["current_stock"] <= $product["minimum_stock"] ? "stock-low" : "stock-good" ?>">
                                            <?= number_format($product["current_stock"], 3) ?>
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="status status-<?= $product["status"] ?>">
                                        <?= ucfirst($product["status"]) ?>
                                    </span>
                                </td>

                                <td>

                                    <a href="edit-product.php?id=<?= urlencode($product["product_id"]) ?>" class="action-link">
                                        Edit
                                    </a>

                                    <span class="action-sep">|</span>

                                    <a
                                        href="products.php?toggle=<?= urlencode($product["product_id"]) ?>"
                                        class="action-link"
                                        onclick="return confirm('Are you sure you want to change the status of this item?');"
                                    >
                                        <?= $product["status"] === "active" ? "Deactivate" : "Activate" ?>
                                    </a>

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