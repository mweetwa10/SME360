<?php
require_once "auth_check.php";
require_once "db.php";

$business_id = $_SESSION["business_id"];
$full_name = $_SESSION["full_name"];
$role_name = $_SESSION["role_name"];

$message = "";
$error = "";

$businessQuery = $pdo->prepare("
    SELECT
        business_name,
        business_type,
        phone,
        email,
        address,
        logo
    FROM businesses
    WHERE business_id = :business_id
    LIMIT 1
");

$businessQuery->execute([
    ":business_id" => $business_id
]);

$business = $businessQuery->fetch(PDO::FETCH_ASSOC);

if (!$business) {
    die("Business information could not be found.");
}

$settingsQuery = $pdo->prepare("
    SELECT *
    FROM business_settings
    WHERE business_id = :business_id
    LIMIT 1
");

$settingsQuery->execute([
    ":business_id" => $business_id
]);

$settings = $settingsQuery->fetch(PDO::FETCH_ASSOC);

if (!$settings) {

    $createSettings = $pdo->prepare("
        INSERT INTO business_settings (
            business_id,
            currency,
            currency_symbol,
            tax_enabled,
            tax_rate,
            allow_credit_sales,
            allow_partial_payments,
            low_stock_alerts,
            default_payment_method,
            invoice_prefix,
            receipt_prefix,
            invoice_footer,
            receipt_footer
        )
        VALUES (
            :business_id,
            'ZMW',
            'K',
            0,
            0.00,
            1,
            1,
            1,
            'cash',
            'INV',
            'REC',
            NULL,
            NULL
        )
    ");

    $createSettings->execute([
        ":business_id" => $business_id
    ]);

    $settingsQuery->execute([
        ":business_id" => $business_id
    ]);

    $settings = $settingsQuery->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_business"])) {

    $business_name = trim($_POST["business_name"] ?? "");
    $business_type = trim($_POST["business_type"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $address = trim($_POST["address"] ?? "");

    if ($business_name === "") {

        $error = "Business name is required.";

    } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {

        try {

            $updateBusiness = $pdo->prepare("
                UPDATE businesses
                SET
                    business_name = :business_name,
                    business_type = :business_type,
                    phone = :phone,
                    email = :email,
                    address = :address
                WHERE business_id = :business_id
            ");

            $updateBusiness->execute([
                ":business_name" => $business_name,
                ":business_type" => $business_type !== "" ? $business_type : null,
                ":phone" => $phone !== "" ? $phone : null,
                ":email" => $email !== "" ? $email : null,
                ":address" => $address !== "" ? $address : null,
                ":business_id" => $business_id
            ]);

            $message = "Business profile updated successfully.";

            $businessQuery->execute([
                ":business_id" => $business_id
            ]);

            $business = $businessQuery->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {

            $error = "Unable to update business profile.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_sales_settings"])) {

    $currency = trim($_POST["currency"] ?? "ZMW");
    $currency_symbol = trim($_POST["currency_symbol"] ?? "K");
    $tax_enabled = isset($_POST["tax_enabled"]) ? 1 : 0;
    $tax_rate = $_POST["tax_rate"] ?? 0;
    $allow_credit_sales = isset($_POST["allow_credit_sales"]) ? 1 : 0;
    $allow_partial_payments = isset($_POST["allow_partial_payments"]) ? 1 : 0;
    $default_payment_method = $_POST["default_payment_method"] ?? "cash";

    $validPaymentMethods = [
        "cash",
        "mobile_money",
        "bank_transfer",
        "debit_card",
        "credit_card",
        "other"
    ];

    if ($currency === "") {

        $error = "Currency is required.";

    } elseif ($currency_symbol === "") {

        $error = "Currency symbol is required.";

    } elseif (!is_numeric($tax_rate) || $tax_rate < 0 || $tax_rate > 100) {

        $error = "Tax rate must be between 0 and 100.";

    } elseif (!in_array($default_payment_method, $validPaymentMethods)) {

        $error = "Invalid default payment method.";

    } else {

        try {

            $updateSettings = $pdo->prepare("
                UPDATE business_settings
                SET
                    currency = :currency,
                    currency_symbol = :currency_symbol,
                    tax_enabled = :tax_enabled,
                    tax_rate = :tax_rate,
                    allow_credit_sales = :allow_credit_sales,
                    allow_partial_payments = :allow_partial_payments,
                    default_payment_method = :default_payment_method
                WHERE business_id = :business_id
            ");

            $updateSettings->execute([
                ":currency" => $currency,
                ":currency_symbol" => $currency_symbol,
                ":tax_enabled" => $tax_enabled,
                ":tax_rate" => (float) $tax_rate,
                ":allow_credit_sales" => $allow_credit_sales,
                ":allow_partial_payments" => $allow_partial_payments,
                ":default_payment_method" => $default_payment_method,
                ":business_id" => $business_id
            ]);

            $message = "Sales and payment settings updated successfully.";

            $settingsQuery->execute([
                ":business_id" => $business_id
            ]);

            $settings = $settingsQuery->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {

            $error = "Unable to update sales settings.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_inventory_settings"])) {

    $low_stock_alerts = isset($_POST["low_stock_alerts"]) ? 1 : 0;

    try {

        $updateInventory = $pdo->prepare("
            UPDATE business_settings
            SET low_stock_alerts = :low_stock_alerts
            WHERE business_id = :business_id
        ");

        $updateInventory->execute([
            ":low_stock_alerts" => $low_stock_alerts,
            ":business_id" => $business_id
        ]);

        $message = "Inventory settings updated successfully.";

        $settingsQuery->execute([
            ":business_id" => $business_id
        ]);

        $settings = $settingsQuery->fetch(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        $error = "Unable to update inventory settings.";
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_document_settings"])) {

    $invoice_prefix = trim($_POST["invoice_prefix"] ?? "INV");
    $receipt_prefix = trim($_POST["receipt_prefix"] ?? "REC");
    $invoice_footer = trim($_POST["invoice_footer"] ?? "");
    $receipt_footer = trim($_POST["receipt_footer"] ?? "");

    if ($invoice_prefix === "") {

        $error = "Invoice prefix is required.";

    } elseif ($receipt_prefix === "") {

        $error = "Receipt prefix is required.";

    } else {

        try {

            $updateDocuments = $pdo->prepare("
                UPDATE business_settings
                SET
                    invoice_prefix = :invoice_prefix,
                    receipt_prefix = :receipt_prefix,
                    invoice_footer = :invoice_footer,
                    receipt_footer = :receipt_footer
                WHERE business_id = :business_id
            ");

            $updateDocuments->execute([
                ":invoice_prefix" => $invoice_prefix,
                ":receipt_prefix" => $receipt_prefix,
                ":invoice_footer" => $invoice_footer !== "" ? $invoice_footer : null,
                ":receipt_footer" => $receipt_footer !== "" ? $receipt_footer : null,
                ":business_id" => $business_id
            ]);

            $message = "Invoice and receipt settings updated successfully.";

            $settingsQuery->execute([
                ":business_id" => $business_id
            ]);

            $settings = $settingsQuery->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {

            $error = "Unable to update document settings.";
        }
    }
}

$businessQuery->execute([
    ":business_id" => $business_id
]);

$business = $businessQuery->fetch(PDO::FETCH_ASSOC);

$settingsQuery->execute([
    ":business_id" => $business_id
]);

$settings = $settingsQuery->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Settings | SME360</title>

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

.settings-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
}

.settings-header {
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 15px;
    margin-bottom: 20px;
}

.settings-header h3 {
    font-size: 17px;
    color: #111827;
}

.settings-header p {
    color: #6b7280;
    font-size: 13px;
    margin-top: 5px;
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
    font-family: inherit;
}

input:focus,
select:focus,
textarea:focus {
    border-color: #22c55e;
}

textarea {
    min-height: 90px;
    resize: vertical;
}

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 0;
}

.checkbox-group input {
    width: 18px;
    height: 18px;
    accent-color: #22c55e;
}

.checkbox-group label {
    margin: 0;
    font-weight: 600;
}

.help-text {
    font-size: 12px;
    color: #9ca3af;
    margin-top: 5px;
}

.form-actions {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
}

.save-button {
    background: #22c55e;
    color: white;
    border: none;
    padding: 12px 22px;
    border-radius: 7px;
    cursor: pointer;
    font-weight: 600;
}

.save-button:hover {
    background: #16a34a;
}

.quick-links {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.quick-link {
    display: block;
    padding: 18px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    text-decoration: none;
    color: #111827;
    transition: 0.2s;
}

.quick-link:hover {
    border-color: #22c55e;
    background: #f0fdf4;
}

.quick-link strong {
    display: block;
    margin-bottom: 5px;
}

.quick-link span {
    color: #6b7280;
    font-size: 12px;
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

    .quick-links {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>

<aside class="sidebar">

    <div class="logo">
        SME<span>360</span>
    </div>

    <div class="nav-title">Main Menu</div>

    <a href="dashboard.php" class="nav-link">
        <span>▦</span>
        <span>Dashboard</span>
    </a>

    <a href="settings.php" class="nav-link active">
        <span>⚙</span>
        <span>Settings</span>
    </a>

</aside>

<main class="main">

<header class="topbar">

    <h1>Settings</h1>

    <div class="user">
        Welcome, <strong><?= htmlspecialchars($full_name) ?></strong>
    </div>

</header>

<div class="content">

<div class="page-header">
    <h2>Business Settings</h2>
    <p>Manage your business profile and configure how SME360 operates.</p>
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

<!-- BUSINESS PROFILE -->

<div class="settings-card">

    <div class="settings-header">
        <h3>Business Profile</h3>
        <p>Update the information associated with your business.</p>
    </div>

    <form method="POST">

        <div class="form-grid">

            <div class="form-group">
                <label>Business Name *</label>
                <input
                    type="text"
                    name="business_name"
                    value="<?= htmlspecialchars($business["business_name"]) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label>Business Type</label>
                <input
                    type="text"
                    name="business_type"
                    value="<?= htmlspecialchars($business["business_type"] ?? "") ?>"
                    placeholder="e.g. Retail, Restaurant, Hardware"
                >
            </div>

            <div class="form-group">
                <label>Phone Number</label>
                <input
                    type="text"
                    name="phone"
                    value="<?= htmlspecialchars($business["phone"] ?? "") ?>"
                >
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input
                    type="email"
                    name="email"
                    value="<?= htmlspecialchars($business["email"] ?? "") ?>"
                >
            </div>

            <div class="form-group full">
                <label>Business Address</label>
                <textarea name="address"><?= htmlspecialchars($business["address"] ?? "") ?></textarea>
            </div>

        </div>

        <div class="form-actions">
            <button type="submit" name="update_business" class="save-button">
                Save Business Profile
            </button>
        </div>

    </form>

</div>

<!-- SALES SETTINGS -->

<div class="settings-card">

    <div class="settings-header">
        <h3>Sales & Payment Settings</h3>
        <p>Configure how sales, payments, credit sales and tax are handled.</p>
    </div>

    <form method="POST">

        <div class="form-grid">

            <div class="form-group">
                <label>Currency Code</label>
                <input
                    type="text"
                    name="currency"
                    value="<?= htmlspecialchars($settings["currency"]) ?>"
                    placeholder="ZMW"
                    maxlength="10"
                    required
                >
            </div>

            <div class="form-group">
                <label>Currency Symbol</label>
                <input
                    type="text"
                    name="currency_symbol"
                    value="<?= htmlspecialchars($settings["currency_symbol"]) ?>"
                    placeholder="K"
                    maxlength="10"
                    required
                >
            </div>

            <div class="form-group">
                <label>Default Payment Method</label>

                <select name="default_payment_method">

                    <?php
                    $paymentMethods = [
                        "cash" => "Cash",
                        "mobile_money" => "Mobile Money",
                        "bank_transfer" => "Bank Transfer",
                        "debit_card" => "Debit Card",
                        "credit_card" => "Credit Card",
                        "other" => "Other"
                    ];
                    ?>

                    <?php foreach ($paymentMethods as $value => $label): ?>

                        <option
                            value="<?= $value ?>"
                            <?= $settings["default_payment_method"] === $value ? "selected" : "" ?>
                        >
                            <?= $label ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group">

                <label>Tax Rate (%)</label>

                <input
                    type="number"
                    name="tax_rate"
                    min="0"
                    max="100"
                    step="0.01"
                    value="<?= htmlspecialchars($settings["tax_rate"]) ?>"
                >

            </div>

            <div class="form-group">

                <div class="checkbox-group">

                    <input
                        type="checkbox"
                        id="tax_enabled"
                        name="tax_enabled"
                        <?= $settings["tax_enabled"] ? "checked" : "" ?>
                    >

                    <label for="tax_enabled">
                        Enable Tax
                    </label>

                </div>

                <div class="help-text">
                    Enable tax calculations on sales and invoices.
                </div>

            </div>

            <div class="form-group">

                <div class="checkbox-group">

                    <input
                        type="checkbox"
                        id="allow_credit_sales"
                        name="allow_credit_sales"
                        <?= $settings["allow_credit_sales"] ? "checked" : "" ?>
                    >

                    <label for="allow_credit_sales">
                        Allow Credit Sales
                    </label>

                </div>

                <div class="help-text">
                    Allow customers to purchase now and pay later.
                </div>

            </div>

            <div class="form-group">

                <div class="checkbox-group">

                    <input
                        type="checkbox"
                        id="allow_partial_payments"
                        name="allow_partial_payments"
                        <?= $settings["allow_partial_payments"] ? "checked" : "" ?>
                    >

                    <label for="allow_partial_payments">
                        Allow Partial Payments
                    </label>

                </div>

                <div class="help-text">
                    Allow customers to pay part of the amount owed.
                </div>

            </div>

        </div>

        <div class="form-actions">

            <button
                type="submit"
                name="update_sales_settings"
                class="save-button"
            >
                Save Sales Settings
            </button>

        </div>

    </form>

</div>

<!-- INVENTORY SETTINGS -->

<div class="settings-card">

    <div class="settings-header">
        <h3>Inventory Settings</h3>
        <p>Configure inventory notifications.</p>
    </div>

    <form method="POST">

        <div class="checkbox-group">

            <input
                type="checkbox"
                id="low_stock_alerts"
                name="low_stock_alerts"
                <?= $settings["low_stock_alerts"] ? "checked" : "" ?>
            >

            <label for="low_stock_alerts">
                Enable Low Stock Alerts
            </label>

        </div>

        <div class="help-text">
            SME360 will identify products that have reached or fallen below their minimum stock level.
        </div>

        <div class="form-actions">

            <button
                type="submit"
                name="update_inventory_settings"
                class="save-button"
            >
                Save Inventory Settings
            </button>

        </div>

    </form>

</div>

<!-- INVOICE & RECEIPT SETTINGS -->

<div class="settings-card">

    <div class="settings-header">
        <h3>Invoice & Receipt Settings</h3>
        <p>Configure invoice and receipt numbering and footer information.</p>
    </div>

    <form method="POST">

        <div class="form-grid">

            <div class="form-group">

                <label>Invoice Prefix</label>

                <input
                    type="text"
                    name="invoice_prefix"
                    value="<?= htmlspecialchars($settings["invoice_prefix"]) ?>"
                    maxlength="20"
                    required
                >

                <div class="help-text">
                    Example: INV0001
                </div>

            </div>

            <div class="form-group">

                <label>Receipt Prefix</label>

                <input
                    type="text"
                    name="receipt_prefix"
                    value="<?= htmlspecialchars($settings["receipt_prefix"]) ?>"
                    maxlength="20"
                    required
                >

                <div class="help-text">
                    Example: REC0001
                </div>

            </div>

            <div class="form-group">

                <label>Invoice Footer</label>

                <textarea
                    name="invoice_footer"
                    placeholder="Thank you for doing business with us."
                ><?= htmlspecialchars($settings["invoice_footer"] ?? "") ?></textarea>

            </div>

            <div class="form-group">

                <label>Receipt Footer</label>

                <textarea
                    name="receipt_footer"
                    placeholder="Thank you for your purchase."
                ><?= htmlspecialchars($settings["receipt_footer"] ?? "") ?></textarea>

            </div>

        </div>

        <div class="form-actions">

            <button
                type="submit"
                name="update_document_settings"
                class="save-button"
            >
                Save Document Settings
            </button>

        </div>

    </form>

</div>

<!-- QUICK LINKS -->

<div class="settings-card">

    <div class="settings-header">
        <h3>Management</h3>
        <p>Quick access to other business management areas.</p>
    </div>

    <div class="quick-links">

        <a href="users.php" class="quick-link">
            <strong>Users & Roles</strong>
            <span>Manage business users and their system roles.</span>
        </a>

        <a href="expense-categories.php" class="quick-link">
            <strong>Expense Categories</strong>
            <span>Manage categories used when recording expenses.</span>
        </a>

        <a href="change-password.php" class="quick-link">
            <strong>Security</strong>
            <span>Change your account password and manage security.</span>
        </a>

    </div>

</div>

</div>

</main>

</body>
</html>