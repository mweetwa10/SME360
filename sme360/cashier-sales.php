<?php
session_start();
require_once "db.php";

// ---- Require login ----
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$business_id = $_SESSION["business_id"];
$user_id     = $_SESSION["user_id"];
$error       = "";

/*
 * Schema assumptions used on this page (adjust to match your actual columns):
 *   sales.customer_name   VARCHAR  - manually entered, no customers table lookup
 *   sales.customer_phone  VARCHAR  - manually entered, no customers table lookup
 *   sales.amount_paid     DECIMAL(12,2) DEFAULT 0
 *   sales.balance         DECIMAL(12,2) DEFAULT 0  (total_amount - amount_paid)
 *   sale_payments table   (payment_id, sale_id, business_id, user_id, amount, created_at)
 *                         - logs every later top-up payment against a sale's balance
 * Customer registration is no longer required anywhere on this page.
 */

// ---- ID generators ----
function generateSaleId($pdo) {
    $last = $pdo->query("SELECT sale_id FROM sales ORDER BY sale_id DESC LIMIT 1")->fetchColumn();
    if (!$last) return "SALE000001";
    $number = intval(substr($last, 4)) + 1;
    return "SALE" . str_pad($number, 6, "0", STR_PAD_LEFT);
}

function generateInventoryTransactionId($pdo) {
    $last = $pdo->query("SELECT inventory_transaction_id FROM inventory_transactions ORDER BY inventory_transaction_id DESC LIMIT 1")->fetchColumn();
    if (!$last) return "INV000001";
    $number = intval(substr($last, 3)) + 1;
    return "INV" . str_pad($number, 6, "0", STR_PAD_LEFT);
}

function generatePaymentId($pdo) {
    $last = $pdo->query("SELECT payment_id FROM sale_payments ORDER BY payment_id DESC LIMIT 1")->fetchColumn();
    if (!$last) return "PAY000001";
    $number = intval(substr($last, 3)) + 1;
    return "PAY" . str_pad($number, 6, "0", STR_PAD_LEFT);
}

// ---- Fetch a sale (used for success displays and repayment lookups) ----
function fetchSale($pdo, $business_id, $sale_id) {
    $stmt = $pdo->prepare("
        SELECT sale_id, customer_name, customer_phone, total_amount, amount_paid, balance, payment_status
        FROM sales
        WHERE sale_id = :sale_id AND business_id = :business_id
        LIMIT 1
    ");
    $stmt->execute([":sale_id" => $sale_id, ":business_id" => $business_id]);
    return $stmt->fetch();
}

// ---- Active products for this business ----
$productQuery = $pdo->prepare("
    SELECT product_id, product_name, product_type, selling_price, current_stock
    FROM products
    WHERE business_id = :business_id AND status = 'active'
    ORDER BY product_name ASC
");
$productQuery->execute([":business_id" => $business_id]);
$products = $productQuery->fetchAll();

$justCreatedSale = null;
$paymentResult   = null;
$paymentError    = "";

// ============================================================
// HANDLE REPAYMENT (top-up payment against an existing balance)
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "record_payment") {

    $payment_sale_id = trim($_POST["payment_sale_id"] ?? "");
    $payment_amount  = trim($_POST["payment_amount"] ?? "");

    if ($payment_sale_id === "") {
        $paymentError = "Please enter the Sale ID.";
    } elseif ($payment_amount === "" || !is_numeric($payment_amount) || $payment_amount <= 0) {
        $paymentError = "Please enter a valid payment amount.";
    } else {
        $sale = fetchSale($pdo, $business_id, $payment_sale_id);

        if (!$sale) {
            $paymentError = "No sale found with that Sale ID.";
        } elseif ((float) $sale["balance"] <= 0) {
            $paymentError = "This sale has no outstanding balance.";
        } elseif ((float) $payment_amount > (float) $sale["balance"]) {
            $paymentError = "Payment amount cannot be more than the outstanding balance of K" . number_format($sale["balance"], 2) . ".";
        } else {
            try {
                $pdo->beginTransaction();

                $newAmountPaid = round((float) $sale["amount_paid"] + (float) $payment_amount, 2);
                $newBalance    = round((float) $sale["total_amount"] - $newAmountPaid, 2);
                $newStatus     = $newBalance <= 0 ? "paid" : "partial";

                $update = $pdo->prepare("
                    UPDATE sales
                    SET amount_paid = :amount_paid, balance = :balance, payment_status = :payment_status
                    WHERE sale_id = :sale_id AND business_id = :business_id
                ");
                $update->execute([
                    ":amount_paid"    => $newAmountPaid,
                    ":balance"        => $newBalance,
                    ":payment_status" => $newStatus,
                    ":sale_id"        => $payment_sale_id,
                    ":business_id"    => $business_id,
                ]);

                $payment_id = generatePaymentId($pdo);
                $insertPayment = $pdo->prepare("
                    INSERT INTO sale_payments (payment_id, sale_id, business_id, user_id, amount)
                    VALUES (:payment_id, :sale_id, :business_id, :user_id, :amount)
                ");
                $insertPayment->execute([
                    ":payment_id"  => $payment_id,
                    ":sale_id"     => $payment_sale_id,
                    ":business_id" => $business_id,
                    ":user_id"     => $user_id,
                    ":amount"      => $payment_amount,
                ]);

                $pdo->commit();

                $paymentResult = [
                    "sale_id"         => $payment_sale_id,
                    "customer_name"   => $sale["customer_name"],
                    "customer_phone"  => $sale["customer_phone"],
                    "amount_paid_now" => (float) $payment_amount,
                    "new_balance"     => $newBalance,
                ];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $paymentError = "Unable to record the payment. Please try again.";
            }
        }
    }
}

// ============================================================
// HANDLE NEW SALE
// ============================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "record_sale") === "record_sale") {

    $customer_name   = trim($_POST["customer_name"] ?? "");
    $customer_phone  = trim($_POST["customer_phone"] ?? "");
    $payment_method  = trim($_POST["payment_method"] ?? "cash");
    $payment_status  = trim($_POST["payment_status"] ?? "paid");
    $discount        = trim($_POST["discount"] ?? "0");
    $tax             = trim($_POST["tax"] ?? "0");
    $notes           = trim($_POST["notes"] ?? "");
    $amount_paid_in  = trim($_POST["amount_paid"] ?? "");
    $product_ids     = $_POST["product_id"] ?? [];
    $quantities      = $_POST["quantity"] ?? [];

    // ---- Validate discount / tax ----
    if ($discount === "" || !is_numeric($discount) || $discount < 0) {
        $error = "Please enter a valid discount.";
    } elseif ($tax === "" || !is_numeric($tax) || $tax < 0) {
        $error = "Please enter a valid tax amount.";
    }

    // ---- Validate payment method / status ----
    $validPaymentMethods  = ["cash", "mobile_money", "bank_transfer", "debit_card", "credit_card", "credit", "other"];
    $validPaymentStatuses = ["paid", "partial", "unpaid"];

    if ($error === "" && !in_array($payment_method, $validPaymentMethods, true)) {
        $error = "Invalid payment method.";
    }
    if ($error === "" && !in_array($payment_status, $validPaymentStatuses, true)) {
        $error = "Invalid payment status.";
    }

    // ---- Credit sale: manual customer details, no registration required ----
    if ($error === "" && $payment_method === "credit") {
        if ($customer_name === "" || $customer_phone === "") {
            $error = "Please enter the customer's name and phone number for a credit sale.";
        }
    }

    // ---- Validate products ----
    if ($error === "") {
        if (empty($product_ids)) {
            $error = "Please add at least one product or service.";
        } elseif (count($product_ids) !== count($quantities)) {
            $error = "Invalid sale items.";
        }
    }

    // ---- Process sale items ----
    $saleItems = [];
    $subtotal  = 0;

    if ($error === "") {
        foreach ($product_ids as $index => $selected_product_id) {
            $selected_product_id = trim($selected_product_id);
            $quantity = $quantities[$index] ?? 0;

            if ($quantity === "" || !is_numeric($quantity) || $quantity <= 0) {
                $error = "Please enter a valid quantity.";
                break;
            }

            $singleProduct = $pdo->prepare("
                SELECT product_id, product_name, product_type, selling_price, current_stock
                FROM products
                WHERE product_id = :product_id AND business_id = :business_id AND status = 'active'
                LIMIT 1
            ");
            $singleProduct->execute([":product_id" => $selected_product_id, ":business_id" => $business_id]);
            $product = $singleProduct->fetch();

            if (!$product) {
                $error = "Invalid product selected.";
                break;
            }

            if ($product["product_type"] === "product" && $quantity > $product["current_stock"]) {
                $error = "Insufficient stock for: " . $product["product_name"];
                break;
            }

            $itemSubtotal = $quantity * $product["selling_price"];
            $subtotal += $itemSubtotal;

            $saleItems[] = [
                "product_id"   => $product["product_id"],
                "product_name" => $product["product_name"],
                "product_type" => $product["product_type"],
                "quantity"     => $quantity,
                "unit_price"   => $product["selling_price"],
                "subtotal"     => $itemSubtotal,
            ];
        }
    }

    // ---- Totals + amount paid / balance ----
    $total_amount = 0;
    $amount_paid  = 0;
    $balance      = 0;

    if ($error === "") {
        $subtotal = round($subtotal, 2);
        $discount = round((float) $discount, 2);
        $tax      = round((float) $tax, 2);
        $total_amount = $subtotal - $discount + $tax;

        if ($total_amount < 0) {
            $error = "Discount cannot be greater than the subtotal.";
        }
    }

    if ($error === "") {
        if ($payment_status === "paid") {
            $amount_paid = $total_amount;
        } elseif ($payment_status === "unpaid") {
            $amount_paid = 0;
        } else { // partial
            if ($amount_paid_in === "" || !is_numeric($amount_paid_in) || $amount_paid_in < 0) {
                $error = "Please enter a valid amount paid for a partial payment.";
            } elseif ($amount_paid_in >= $total_amount) {
                $error = "Amount paid must be less than the total for a partial payment.";
            } else {
                $amount_paid = round((float) $amount_paid_in, 2);
            }
        }
    }

    if ($error === "") {
        $balance = round($total_amount - $amount_paid, 2);
    }

    // ---- Save sale ----
    if ($error === "") {
        try {
            $pdo->beginTransaction();

            $sale_id = generateSaleId($pdo);

            $insertSale = $pdo->prepare("
                INSERT INTO sales (
                    sale_id, business_id, user_id, customer_name, customer_phone,
                    subtotal, discount, tax, total_amount, amount_paid, balance,
                    payment_method, payment_status, sale_status, notes
                ) VALUES (
                    :sale_id, :business_id, :user_id, :customer_name, :customer_phone,
                    :subtotal, :discount, :tax, :total_amount, :amount_paid, :balance,
                    :payment_method, :payment_status, 'completed', :notes
                )
            ");
            $insertSale->execute([
                ":sale_id"         => $sale_id,
                ":business_id"     => $business_id,
                ":user_id"         => $user_id,
                ":customer_name"   => $customer_name !== "" ? $customer_name : null,
                ":customer_phone"  => $customer_phone !== "" ? $customer_phone : null,
                ":subtotal"        => $subtotal,
                ":discount"        => $discount,
                ":tax"             => $tax,
                ":total_amount"    => $total_amount,
                ":amount_paid"     => $amount_paid,
                ":balance"         => $balance,
                ":payment_method"  => $payment_method,
                ":payment_status"  => $payment_status,
                ":notes"           => $notes !== "" ? $notes : null,
            ]);

            $insertItem = $pdo->prepare("
                INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount, subtotal)
                VALUES (:sale_id, :product_id, :quantity, :unit_price, 0.00, :subtotal)
            ");

            $updateStock = $pdo->prepare("
                UPDATE products SET current_stock = current_stock - :quantity
                WHERE product_id = :product_id AND business_id = :business_id
            ");

            $insertInventory = $pdo->prepare("
                INSERT INTO inventory_transactions (
                    inventory_transaction_id, business_id, product_id, user_id,
                    transaction_type, quantity, reason, reference_type, reference_id
                ) VALUES (
                    :inventory_transaction_id, :business_id, :product_id, :user_id,
                    'sale', :quantity, 'Product sold', 'sale', :sale_id
                )
            ");

            foreach ($saleItems as $item) {
                $insertItem->execute([
                    ":sale_id"    => $sale_id,
                    ":product_id" => $item["product_id"],
                    ":quantity"   => $item["quantity"],
                    ":unit_price" => $item["unit_price"],
                    ":subtotal"   => $item["subtotal"],
                ]);

                if ($item["product_type"] === "product") {
                    $updateStock->execute([
                        ":quantity"    => $item["quantity"],
                        ":product_id"  => $item["product_id"],
                        ":business_id" => $business_id,
                    ]);

                    $inventory_id = generateInventoryTransactionId($pdo);
                    $insertInventory->execute([
                        ":inventory_transaction_id" => $inventory_id,
                        ":business_id"               => $business_id,
                        ":product_id"                => $item["product_id"],
                        ":user_id"                   => $user_id,
                        ":quantity"                  => $item["quantity"],
                        ":sale_id"                    => $sale_id,
                    ]);
                }
            }

            $pdo->commit();

            header("Location: sales.php?success=1&sale_id=" . urlencode($sale_id));
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "Unable to save the sale. Please try again.";
        }
    }
}

// ---- Success banner details (sale just recorded on a previous request) ----
if (isset($_GET["success"]) && $_GET["success"] === "1") {
    $justCreatedSale = fetchSale($pdo, $business_id, $_GET["sale_id"] ?? "");
}

// ---- All sales of products and services (for the table at the bottom of the page) ----
$allSalesQuery = $pdo->prepare("
    SELECT
        s.sale_id, s.customer_name, s.customer_phone, s.payment_method, s.payment_status,
        s.total_amount, s.balance, s.created_at,
        si.quantity, si.unit_price, si.subtotal,
        p.product_name, p.product_type
    FROM sale_items si
    JOIN sales s ON s.sale_id = si.sale_id
    JOIN products p ON p.product_id = si.product_id
    WHERE s.business_id = :business_id
    ORDER BY s.created_at DESC, s.sale_id DESC
");
$allSalesQuery->execute([":business_id" => $business_id]);
$allSaleItems = $allSalesQuery->fetchAll();

// ---- Sales with an outstanding balance (for the searchable customer/ID picker) ----
$outstandingQuery = $pdo->prepare("
    SELECT sale_id, customer_name, customer_phone, balance
    FROM sales
    WHERE business_id = :business_id AND balance > 0
    ORDER BY sale_id DESC
");
$outstandingQuery->execute([":business_id" => $business_id]);
$outstandingSales = $outstandingQuery->fetchAll();

// Options used by the search/select picker below (sale_id is always the real value submitted)
$outstandingOptions = [];
foreach ($outstandingSales as $os) {
    $customerLabel = ($os["customer_name"] !== null && $os["customer_name"] !== "") ? $os["customer_name"] : "No name";
    $outstandingOptions[] = [
        "sale_id" => $os["sale_id"],
        "label"   => $os["sale_id"] . " — " . $customerLabel . " (K" . number_format((float) $os["balance"], 2) . " owing)",
        "balance" => (float) $os["balance"],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Record Sale | SME360</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Arial, Helvetica, sans-serif; background: #f4f7fb; min-height: 100vh; padding: 30px; }
.container { max-width: 1100px; margin: auto; }
.header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
.header h1 { color: #111827; font-size: 26px; }
.header p { color: #6b7280; margin-top: 5px; font-size: 14px; }
.back-button { text-decoration: none; background: #111827; color: #ffffff; padding: 11px 18px; border-radius: 7px; font-size: 14px; }
.card { background: #ffffff; padding: 25px; border-radius: 14px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06); margin-bottom: 20px; }
.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 13px; border-radius: 8px; margin-bottom: 20px; }
.success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; padding: 13px; border-radius: 8px; margin-bottom: 20px; }
.success .balance-line { display: block; margin-top: 6px; font-weight: 700; }
.form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
.form-group { margin-bottom: 15px; }
label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 7px; }
input, select, textarea { width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 7px; outline: none; font-size: 14px; }
input:focus, select:focus, textarea:focus { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.12); }
textarea { resize: vertical; min-height: 80px; }
.customer-help { display: block; margin-top: 6px; color: #6b7280; font-size: 12px; }
.sale-items-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.sale-items-header h2 { font-size: 18px; color: #111827; }
.add-item { background: #111827; color: white; border: none; padding: 10px 15px; border-radius: 7px; cursor: pointer; }
.item-row { display: grid; grid-template-columns: 2fr 1fr 1fr 40px; gap: 10px; align-items: end; margin-bottom: 12px; }
.remove-item { width: 40px; height: 40px; border: none; background: #fee2e2; color: #b91c1c; border-radius: 7px; cursor: pointer; font-size: 18px; }
.summary { max-width: 400px; margin-left: auto; }
.summary-row { display: flex; justify-content: space-between; padding: 8px 0; color: #4b5563; }
.summary-total { border-top: 1px solid #e5e7eb; margin-top: 8px; padding-top: 15px; font-size: 20px; font-weight: 700; color: #111827; }
.submit-section { display: flex; justify-content: flex-end; margin-top: 20px; }
.submit-button { border: none; background: #22c55e; color: white; padding: 13px 25px; border-radius: 7px; font-size: 15px; font-weight: 700; cursor: pointer; }
.submit-button:hover { background: #16a34a; }
.credit-fields { display: none; grid-column: 1 / -1; grid-template-columns: repeat(2, 1fr); gap: 20px; }
.credit-fields.show { display: grid; }
.partial-field { display: none; }
.partial-field.show { display: block; }
.payment-form-grid { display: grid; grid-template-columns: 1.2fr 1.4fr 1fr auto; gap: 15px; align-items: end; }
.sales-table-wrapper { overflow-x: auto; }
.sales-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sales-table th, .sales-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
.sales-table th { color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; }
.sales-table tbody tr:hover { background: #f9fafb; }
.type-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.type-badge.product { background: #dbeafe; color: #1d4ed8; }
.type-badge.service { background: #ede9fe; color: #6d28d9; }
.status-badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
.status-badge.paid { background: #dcfce7; color: #15803d; }
.status-badge.partial { background: #fef9c3; color: #a16207; }
.status-badge.unpaid { background: #fee2e2; color: #b91c1c; }
.empty-state { color: #6b7280; font-size: 14px; padding: 15px 0; }
.pay-btn { border: none; background: #22c55e; color: #fff; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; }
.pay-btn:hover { background: #16a34a; }
@media (max-width: 700px) {
  body { padding: 15px; }
  .header { flex-direction: column; align-items: flex-start; gap: 15px; }
  .form-grid, .credit-fields { grid-template-columns: 1fr; }
  .item-row { grid-template-columns: 1fr; }
  .remove-item { width: 100%; }
  .payment-form-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="container">

<div class="header">
    <div>
        <h1>Record Sale</h1>
        <p>Create a new sales transaction.</p>
    </div>
   <a href="#" class="back-button" onclick="history.back();return false;">← Back</a>
</div>

<?php if ($error !== ""): ?>
<div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($justCreatedSale): ?>
<div class="success">
    Sale recorded successfully. Sale ID: <?= htmlspecialchars($justCreatedSale["sale_id"]) ?>
    <?php if ($justCreatedSale["customer_name"]): ?>
        <br>Customer: <?= htmlspecialchars($justCreatedSale["customer_name"]) ?> (<?= htmlspecialchars($justCreatedSale["customer_phone"]) ?>)
    <?php endif; ?>
    <?php if ((float) $justCreatedSale["balance"] > 0): ?>
        <span class="balance-line">Amount owing: K<?= number_format($justCreatedSale["balance"], 2) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($paymentError !== ""): ?>
<div class="error"><?= htmlspecialchars($paymentError) ?></div>
<?php endif; ?>

<?php if ($paymentResult): ?>
<div class="success">
    Payment of K<?= number_format($paymentResult["amount_paid_now"], 2) ?> recorded for Sale <?= htmlspecialchars($paymentResult["sale_id"]) ?>.
    <?php if ($paymentResult["customer_name"]): ?>
        <br>Customer: <?= htmlspecialchars($paymentResult["customer_name"]) ?> (<?= htmlspecialchars($paymentResult["customer_phone"]) ?>)
    <?php endif; ?>
    <span class="balance-line">
        <?= $paymentResult["new_balance"] > 0
            ? "New balance owing: K" . number_format($paymentResult["new_balance"], 2)
            : "Balance fully settled." ?>
    </span>
</div>
<?php endif; ?>

<form method="POST" action="">
<input type="hidden" name="action" value="record_sale">

<div class="card">
    <div class="form-grid">

        <div class="form-group">
            <label for="payment_method">Payment Method</label>
            <select name="payment_method" id="payment_method" required>
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="debit_card">Debit Card</option>
                <option value="credit_card">Credit Card</option>
                <option value="credit">Credit</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div class="form-group">
            <label for="payment_status">Payment Status</label>
            <select name="payment_status" id="payment_status" required>
                <option value="paid">Paid</option>
                <option value="partial">Partially Paid</option>
                <option value="unpaid">Unpaid</option>
            </select>
        </div>

        <div class="credit-fields" id="creditFields">
            <div class="form-group">
                <label for="customer_name">Customer Name</label>
                <input type="text" name="customer_name" id="customer_name" placeholder="Enter customer name">
                <small class="customer-help">No prior registration needed — just enter their details here.</small>
            </div>
            <div class="form-group">
                <label for="customer_phone">Customer Phone Number</label>
                <input type="text" name="customer_phone" id="customer_phone" placeholder="Enter customer phone number" autocomplete="tel">
            </div>
        </div>

        <div class="form-group partial-field" id="amountPaidField">
            <label for="amount_paid">Amount Paid Now</label>
            <input type="number" name="amount_paid" id="amount_paid" min="0" step="0.01" placeholder="Amount received from customer" onchange="calculateTotals()">
            <small class="customer-help">The remaining balance will be tracked as owing.</small>
        </div>

        <div class="form-group">
            <label for="discount">Discount</label>
            <input type="number" name="discount" id="discount" value="0" min="0" step="0.01">
        </div>

        <div class="form-group">
            <label for="tax">Tax</label>
            <input type="number" name="tax" id="tax" value="0" min="0" step="0.01">
        </div>

        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea name="notes" id="notes" placeholder="Optional notes"></textarea>
        </div>

    </div>
</div>

<div class="card">
    <div class="sale-items-header">
        <h2>Sale Items</h2>
        <button type="button" class="add-item" onclick="addItem()">+ Add Item</button>
    </div>

    <div id="itemsContainer">
        <div class="item-row">
            <div>
                <label>Product / Service</label>
                <select name="product_id[]" class="product-select" onchange="calculateTotals()" required>
                    <option value="">Select Product or Service</option>
                    <?php foreach ($products as $product): ?>
                        <option
                            value="<?= htmlspecialchars($product["product_id"]) ?>"
                            data-price="<?= htmlspecialchars($product["selling_price"]) ?>"
                            data-stock="<?= htmlspecialchars($product["current_stock"]) ?>"
                            data-type="<?= htmlspecialchars($product["product_type"]) ?>"
                        >
                            <?= htmlspecialchars($product["product_name"]) ?> - K<?= number_format($product["selling_price"], 2) ?>
                            <?php if ($product["product_type"] === "product"): ?>
                                | Stock: <?= htmlspecialchars($product["current_stock"]) ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Quantity</label>
                <input type="number" name="quantity[]" class="quantity-input" min="0.001" step="0.001" value="1" onchange="calculateTotals()" required>
            </div>

            <div>
                <label>Item Total</label>
                <input type="text" class="item-total" value="K0.00" readonly>
            </div>

            <button type="button" class="remove-item" onclick="removeItem(this)">×</button>
        </div>
    </div>
</div>

<div class="card">
    <div class="summary">
        <div class="summary-row"><span>Subtotal</span><strong id="subtotalDisplay">K0.00</strong></div>
        <div class="summary-row"><span>Discount</span><strong id="discountDisplay">K0.00</strong></div>
        <div class="summary-row"><span>Tax</span><strong id="taxDisplay">K0.00</strong></div>
        <div class="summary-row"><span>Amount Paid</span><strong id="amountPaidDisplay">K0.00</strong></div>
        <div class="summary-row"><span>Balance Owing</span><strong id="balanceDisplay">K0.00</strong></div>
        <div class="summary-row summary-total"><span>Total</span><strong id="totalDisplay">K0.00</strong></div>
    </div>

    <div class="submit-section">
        <button type="submit" class="submit-button">Complete Sale</button>
    </div>
</div>

</form>

<!-- ---- Credit customers: pay towards an outstanding balance ---- -->
<div class="card">
    <div class="sale-items-header">
        <h2>Make a Payment Towards a Balance</h2>
    </div>
    <form method="POST" action="" id="paymentForm">
        <input type="hidden" name="action" value="record_payment">
        <div class="payment-form-grid">
            <div class="form-group">
                <label for="customerSearch">Search Customer / Sale</label>
                <input type="text" id="customerSearch" placeholder="Type a customer name or Sale ID..." autocomplete="off">
            </div>
            <div class="form-group">
                <label for="paymentSaleSelect">Customer / Sale</label>
                <select name="payment_sale_id" id="paymentSaleSelect" required>
                    <option value="">-- Select a customer/sale --</option>
                    <?php foreach ($outstandingOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt["sale_id"]) ?>" data-balance="<?= $opt["balance"] ?>">
                            <?= htmlspecialchars($opt["label"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($outstandingOptions)): ?>
                    <small class="customer-help">No customers currently have an outstanding balance.</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="payment_amount">Payment Amount</label>
                <input type="number" name="payment_amount" id="payment_amount" min="0.01" step="0.01" placeholder="Amount received" required>
            </div>
            <button type="submit" class="submit-button">Record Payment</button>
        </div>
        <small class="customer-help">Applies the payment to the sale's outstanding balance and updates it immediately. You can also click "Pay" next to any outstanding sale in the table below.</small>
    </form>
</div>

<!-- ---- All sales of products and services ---- -->
<div class="card">
    <div class="sale-items-header">
        <h2>All Sales (Products &amp; Services)</h2>
    </div>

    <div class="sales-table-wrapper">
        <?php if (empty($allSaleItems)): ?>
            <p class="empty-state">No sales recorded yet.</p>
        <?php else: ?>
        <table class="sales-table">
            <thead>
                <tr>
                    <th>Sale ID</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Item Subtotal</th>
                    <th>Payment Method</th>
                    <th>Status</th>
                    <th>Sale Total</th>
                    <th>Balance</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allSaleItems as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row["sale_id"]) ?></td>
                    <td><?= $row["created_at"] ? htmlspecialchars(date("d M Y, H:i", strtotime($row["created_at"]))) : "—" ?></td>
                    <td><?= $row["customer_name"] ? htmlspecialchars($row["customer_name"]) : "—" ?></td>
                    <td><?= $row["customer_phone"] ? htmlspecialchars($row["customer_phone"]) : "—" ?></td>
                    <td><?= htmlspecialchars($row["product_name"]) ?></td>
                    <td><span class="type-badge <?= htmlspecialchars($row["product_type"]) ?>"><?= htmlspecialchars($row["product_type"]) ?></span></td>
                    <td><?= htmlspecialchars(rtrim(rtrim(number_format((float) $row["quantity"], 3, ".", ""), "0"), ".")) ?></td>
                    <td>K<?= number_format($row["unit_price"], 2) ?></td>
                    <td>K<?= number_format($row["subtotal"], 2) ?></td>
                    <td><?= htmlspecialchars(str_replace("_", " ", ucwords($row["payment_method"], "_"))) ?></td>
                    <td><span class="status-badge <?= htmlspecialchars($row["payment_status"]) ?>"><?= htmlspecialchars($row["payment_status"]) ?></span></td>
                    <td>K<?= number_format($row["total_amount"], 2) ?></td>
                    <td>K<?= number_format($row["balance"], 2) ?></td>
                    <td>
                        <?php if ((float) $row["balance"] > 0): ?>
                            <button type="button" class="pay-btn" onclick="fillPayment('<?= htmlspecialchars($row["sale_id"], ENT_QUOTES) ?>', <?= (float) $row["balance"] ?>)">Pay</button>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

</div>

<script>
function addItem() {
    const container = document.getElementById("itemsContainer");
    const firstRow = container.querySelector(".item-row");
    const newRow = firstRow.cloneNode(true);

    newRow.querySelector(".product-select").value = "";
    newRow.querySelector(".quantity-input").value = "1";
    newRow.querySelector(".item-total").value = "K0.00";

    container.appendChild(newRow);
    calculateTotals();
}

function removeItem(button) {
    const rows = document.querySelectorAll(".item-row");
    if (rows.length <= 1) return;

    button.closest(".item-row").remove();
    calculateTotals();
}

function calculateTotals() {
    const rows = document.querySelectorAll(".item-row");
    let subtotal = 0;

    rows.forEach(function (row) {
        const select = row.querySelector(".product-select");
        const quantity = parseFloat(row.querySelector(".quantity-input").value) || 0;
        const option = select.options[select.selectedIndex];
        const price = parseFloat(option?.dataset.price) || 0;
        const itemTotal = price * quantity;

        row.querySelector(".item-total").value = "K" + itemTotal.toFixed(2);
        subtotal += itemTotal;
    });

    const discount = parseFloat(document.getElementById("discount").value) || 0;
    const tax = parseFloat(document.getElementById("tax").value) || 0;
    const total = Math.max(subtotal - discount + tax, 0);

    const paymentStatus = document.getElementById("payment_status").value;
    let amountPaid = 0;
    if (paymentStatus === "paid") {
        amountPaid = total;
    } else if (paymentStatus === "partial") {
        amountPaid = parseFloat(document.getElementById("amount_paid").value) || 0;
    }
    const balance = Math.max(total - amountPaid, 0);

    document.getElementById("subtotalDisplay").textContent = "K" + subtotal.toFixed(2);
    document.getElementById("discountDisplay").textContent = "K" + discount.toFixed(2);
    document.getElementById("taxDisplay").textContent = "K" + tax.toFixed(2);
    document.getElementById("amountPaidDisplay").textContent = "K" + amountPaid.toFixed(2);
    document.getElementById("balanceDisplay").textContent = "K" + balance.toFixed(2);
    document.getElementById("totalDisplay").textContent = "K" + total.toFixed(2);
}

document.getElementById("discount").addEventListener("input", calculateTotals);
document.getElementById("tax").addEventListener("input", calculateTotals);

const paymentMethod = document.getElementById("payment_method");
const creditFields = document.getElementById("creditFields");
const customerName = document.getElementById("customer_name");
const customerPhone = document.getElementById("customer_phone");

function toggleCreditFields() {
    const isCredit = paymentMethod.value === "credit";
    creditFields.classList.toggle("show", isCredit);
    customerName.required = isCredit;
    customerPhone.required = isCredit;
}
paymentMethod.addEventListener("change", toggleCreditFields);

const paymentStatusSelect = document.getElementById("payment_status");
const amountPaidField = document.getElementById("amountPaidField");
const amountPaidInput = document.getElementById("amount_paid");

function togglePartialField() {
    const isPartial = paymentStatusSelect.value === "partial";
    amountPaidField.classList.toggle("show", isPartial);
    amountPaidInput.required = isPartial;
    calculateTotals();
}
paymentStatusSelect.addEventListener("change", togglePartialField);

toggleCreditFields();
togglePartialField();
calculateTotals();

// ---- Outstanding-balance customer/sale picker ----
const outstandingOptions = <?php echo json_encode($outstandingOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

const customerSearch     = document.getElementById("customerSearch");
const paymentSaleSelect  = document.getElementById("paymentSaleSelect");
const paymentAmountField = document.getElementById("payment_amount");

function renderSaleOptions(filterText) {
    const previousValue = paymentSaleSelect.value;
    const term = (filterText || "").trim().toLowerCase();

    const filtered = term
        ? outstandingOptions.filter(function (o) { return o.label.toLowerCase().includes(term); })
        : outstandingOptions;

    paymentSaleSelect.innerHTML = "";

    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = filtered.length ? "-- Select a customer/sale --" : "No matches found";
    paymentSaleSelect.appendChild(placeholder);

    filtered.forEach(function (o) {
        const opt = document.createElement("option");
        opt.value = o.sale_id;
        opt.textContent = o.label;
        opt.dataset.balance = o.balance;
        paymentSaleSelect.appendChild(opt);
    });

    if (filtered.some(function (o) { return o.sale_id === previousValue; })) {
        paymentSaleSelect.value = previousValue;
    }
}

customerSearch.addEventListener("input", function () {
    renderSaleOptions(this.value);
});

paymentSaleSelect.addEventListener("change", function () {
    const selected = this.options[this.selectedIndex];
    const balance = parseFloat(selected ? selected.dataset.balance : NaN);
    if (!isNaN(balance)) {
        paymentAmountField.value = balance.toFixed(2);
        paymentAmountField.max = balance;
    }
});

function fillPayment(saleId, balance) {
    customerSearch.value = "";
    renderSaleOptions("");
    paymentSaleSelect.value = saleId;
    paymentAmountField.value = balance.toFixed(2);
    paymentAmountField.max = balance;

    document.getElementById("paymentForm").scrollIntoView({ behavior: "smooth", block: "center" });
    paymentAmountField.focus();
    paymentAmountField.select();
}
</script>

</body>
</html>