<?php

require_once "auth_check.php";
require_once "db.php";

$business_id = $_SESSION["business_id"];

$message = "";
$error = "";

$product_id = trim($_GET["id"] ?? $_POST["product_id"] ?? "");

if ($product_id === "") {
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

$categories = $categoryQuery->fetchAll(PDO::FETCH_ASSOC);

$productQuery = $pdo->prepare("
    SELECT
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
    FROM products
    WHERE product_id = :product_id
    AND business_id = :business_id
    LIMIT 1
");

$productQuery->execute([
    ":product_id" => $product_id,
    ":business_id" => $business_id
]);

$product = $productQuery->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: products.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_product"])) {

    $product_name = trim($_POST["product_name"] ?? "");
    $category_id = trim($_POST["category_id"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $product_type = $_POST["product_type"] ?? "product";
    $cost_price = $_POST["cost_price"] ?? 0;
    $selling_price = $_POST["selling_price"] ?? 0;
    $current_stock = $_POST["current_stock"] ?? 0;
    $minimum_stock = $_POST["minimum_stock"] ?? 0;
    $status = $_POST["status"] ?? "active";

    if ($product_name === "") {

        $error = "Please enter the product or service name.";

    } elseif (!in_array($product_type, ["product", "service"], true)) {

        $error = "Invalid product type.";

    } elseif (!in_array($status, ["active", "inactive"], true)) {

        $error = "Invalid product status.";

    } elseif (!is_numeric($cost_price) || $cost_price < 0) {

        $error = "Please enter a valid cost price.";

    } elseif (!is_numeric($selling_price) || $selling_price < 0) {

        $error = "Please enter a valid selling price.";

    } elseif (!is_numeric($current_stock) || $current_stock < 0) {

        $error = "Please enter a valid current stock.";

    } elseif (!is_numeric($minimum_stock) || $minimum_stock < 0) {

        $error = "Please enter a valid minimum stock.";

    }


    if ($error === "" && $product_type === "service") {

        $current_stock = 0;
        $minimum_stock = 0;

    }

    if ($error === "" && $category_id !== "") {

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

            $update = $pdo->prepare("
                UPDATE products
                SET
                    category_id = :category_id,
                    product_name = :product_name,
                    description = :description,
                    product_type = :product_type,
                    cost_price = :cost_price,
                    selling_price = :selling_price,
                    current_stock = :current_stock,
                    minimum_stock = :minimum_stock,
                    status = :status
                WHERE product_id = :product_id
                AND business_id = :business_id
            ");

            $update->execute([
                ":category_id" => $category_id !== "" ? $category_id : null,
                ":product_name" => $product_name,
                ":description" => $description !== "" ? $description : null,
                ":product_type" => $product_type,
                ":cost_price" => (float) $cost_price,
                ":selling_price" => (float) $selling_price,
                ":current_stock" => (float) $current_stock,
                ":minimum_stock" => (float) $minimum_stock,
                ":status" => $status,
                ":product_id" => $product_id,
                ":business_id" => $business_id
            ]);

            header("Location: products.php?updated=1");
            exit;

        } catch (PDOException $e) {

            $error = "Unable to update product. Database Error: " . $e->getMessage();

        }

    }

    $product["product_name"] = $product_name;
    $product["category_id"] = $category_id;
    $product["description"] = $description;
    $product["product_type"] = $product_type;
    $product["cost_price"] = $cost_price;
    $product["selling_price"] = $selling_price;
    $product["current_stock"] = $current_stock;
    $product["minimum_stock"] = $minimum_stock;
    $product["status"] = $status;

}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Product | SME360</title>


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

.form-card {
    background: white;
    padding: 30px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    max-width: 900px;
}

.form-card h3 {
    font-size: 18px;
    margin-bottom: 5px;
}

.product-id {
    color: #9ca3af;
    font-size: 12px;
    margin-bottom: 25px;
}

.message {
    padding: 13px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
}

.error {
    background: #fee2e2;
    color: #991b1b;
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
    background: white;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #22c55e;
}

textarea {
    min-height: 100px;
    resize: vertical;
}

.form-actions {
    margin-top: 25px;
    display: flex;
    gap: 10px;
}

.submit-button {
    background: #22c55e;
    color: white;
    border: none;
    padding: 12px 22px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
}

.submit-button:hover {
    background: #16a34a;
}

.cancel-button {
    background: #e5e7eb;
    color: #374151;
    border: none;
    padding: 12px 22px;
    border-radius: 7px;
    cursor: pointer;
    text-decoration: none;
    font-size: 14px;
}

.cancel-button:hover {
    background: #d1d5db;
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

}

</style>


<script>

function toggleStockFields() {

    const type = document.getElementById("product_type").value;

    const stockFields = document.getElementById("stockFields");

    const minimumStockField = document.getElementById("minimumStockField");


    if (type === "service") {

        stockFields.style.display = "none";

        minimumStockField.style.display = "none";

    } else {

        stockFields.style.display = "flex";

        minimumStockField.style.display = "flex";

    }

}

document.addEventListener("DOMContentLoaded", function () {

    toggleStockFields();

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


    <a href="products.php" class="nav-link active">
        <span>🛒</span>
        <span>Products</span>
    </a>


</aside>


<main class="main">


<header class="topbar">

    <div>
        <h1>Edit Product / Service</h1>
    </div>

    <div class="user">
        Welcome,
        <strong>
            <?= htmlspecialchars($_SESSION["full_name"]) ?>
        </strong>
    </div>

</header>


<div class="content">


<div class="page-header">

    <h2>Edit Product or Service</h2>

    <p>
        Update the details, pricing, stock, or status of this item.
    </p>

</div>


<?php if ($error !== ""): ?>

    <div class="message error">
        <?= htmlspecialchars($error) ?>
    </div>

<?php endif; ?>


<div class="form-card">

    <h3>
        <?= htmlspecialchars($product["product_name"]) ?>
    </h3>

    <div class="product-id">
        Product ID: <?= htmlspecialchars($product["product_id"]) ?>
    </div>


<form method="POST" action="">


<input
    type="hidden"
    name="product_id"
    value="<?= htmlspecialchars($product["product_id"]) ?>"
>


<div class="form-grid">


<div class="form-group">

    <label>Product / Service Name *</label>

    <input
        type="text"
        name="product_name"
        value="<?= htmlspecialchars($product["product_name"]) ?>"
        required
    >

</div>


<div class="form-group">

    <label>Type *</label>

    <select
        name="product_type"
        id="product_type"
        onchange="toggleStockFields()"
    >

        <option
            value="product"
            <?= $product["product_type"] === "product" ? "selected" : "" ?>
        >
            Product
        </option>

        <option
            value="service"
            <?= $product["product_type"] === "service" ? "selected" : "" ?>
        >
            Service
        </option>

    </select>

</div>


<div class="form-group">

    <label>Category</label>

    <select name="category_id">

        <option value="">No Category</option>

        <?php foreach ($categories as $category): ?>

            <option
                value="<?= htmlspecialchars($category["category_id"]) ?>"
                <?= $product["category_id"] === $category["category_id"] ? "selected" : "" ?>
            >

                <?= htmlspecialchars($category["category_name"]) ?>

            </option>

        <?php endforeach; ?>

    </select>

</div>


<div class="form-group">

    <label>Cost Price</label>

    <input
        type="number"
        name="cost_price"
        min="0"
        step="0.01"
        value="<?= htmlspecialchars($product["cost_price"]) ?>"
        required
    >

</div>


<div class="form-group">

    <label>Selling Price *</label>

    <input
        type="number"
        name="selling_price"
        min="0"
        step="0.01"
        value="<?= htmlspecialchars($product["selling_price"]) ?>"
        required
    >

</div>


<div
    class="form-group"
    id="stockFields"
>

    <label>Current Stock</label>

    <input
        type="number"
        name="current_stock"
        min="0"
        step="0.001"
        value="<?= htmlspecialchars($product["current_stock"]) ?>"
    >

</div>


<div
    class="form-group"
    id="minimumStockField"
>

    <label>Minimum Stock</label>

    <input
        type="number"
        name="minimum_stock"
        min="0"
        step="0.001"
        value="<?= htmlspecialchars($product["minimum_stock"]) ?>"
    >

</div>


<div class="form-group">

    <label>Status</label>

    <select name="status">

        <option
            value="active"
            <?= $product["status"] === "active" ? "selected" : "" ?>
        >
            Active
        </option>

        <option
            value="inactive"
            <?= $product["status"] === "inactive" ? "selected" : "" ?>
        >
            Inactive
        </option>

    </select>

</div>


<div class="form-group full">

    <label>Description</label>

    <textarea
        name="description"
        placeholder="Optional description"
    ><?= htmlspecialchars($product["description"] ?? "") ?></textarea>

</div>


</div>


<div class="form-actions">

    <button
        type="submit"
        name="update_product"
        class="submit-button"
    >
        Save Changes
    </button>


    <a
        href="products.php"
        class="cancel-button"
    >
        Cancel
    </a>

</div>


</form>

</div>

</div>

</main>


</body>

</html>