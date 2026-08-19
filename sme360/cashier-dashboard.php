<?php
require_once "auth_check.php";
require_once "db.php";

if ($_SESSION["role_name"] !== "Cashier") {
    header("Location: login.php");
    exit;
}

$business_id = $_SESSION["business_id"];
$user_id = $_SESSION["user_id"];
$full_name = $_SESSION["full_name"];
$role_name = $_SESSION["role_name"];

$business = $pdo->prepare("SELECT business_name FROM businesses WHERE business_id=? LIMIT 1");
$business->execute([$business_id]);
$business_name = $business->fetchColumn() ?: 'My Business';

$today = date("Y-m-d");

$q = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM sales WHERE business_id=? AND user_id=? AND DATE(sale_date)=? AND sale_status='completed'");
$q->execute([$business_id, $user_id, $today]);
$todaySales = $q->fetchColumn();

$q = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE business_id=? AND user_id=? AND DATE(sale_date)=? AND sale_status='completed'");
$q->execute([$business_id, $user_id, $today]);
$todayTransactions = $q->fetchColumn();

$q = $pdo->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM sales WHERE business_id=? AND user_id=? AND DATE(sale_date)=? AND payment_method='cash' AND sale_status='completed'");
$q->execute([$business_id, $user_id, $today]);
$cashSales = $q->fetchColumn();

$q = $pdo->prepare("SELECT COALESCE(SUM(balance),0) FROM sales WHERE business_id=? AND user_id=? AND DATE(sale_date)=? AND balance>0 AND sale_status='completed'");
$q->execute([$business_id, $user_id, $today]);
$creditSales = $q->fetchColumn();

$q = $pdo->prepare("
SELECT payment_method,COALESCE(SUM(amount_paid),0) total
FROM sales
WHERE business_id=? AND user_id=? AND DATE(sale_date)=? AND sale_status='completed'
GROUP BY payment_method");
$q->execute([$business_id, $user_id, $today]);
$payments = [];
foreach ($q as $r) $payments[$r["payment_method"]] = $r["total"];

$q = $pdo->prepare("
SELECT sale_id,sale_date,total_amount,payment_method,payment_status,
COALESCE(customer_name,'Walk-in Customer') customer_name
FROM sales
WHERE business_id=? AND user_id=?
ORDER BY sale_date DESC
LIMIT 8");
$q->execute([$business_id, $user_id]);
$recentSales = $q->fetchAll();

function money($v) { return "K " . number_format($v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cashier Dashboard | SME360</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;background:#f4f7fb;color:#1f2937}
.layout{display:flex;min-height:100vh}
.sidebar{width:250px;background:#111827;color:#fff;position:fixed;top:0;left:0;bottom:0;padding:25px 18px}
.logo{font-size:28px;font-weight:700;padding:0 15px 30px}
.logo span{color:#22c55e}
.business{background:#1f2937;padding:15px;border-radius:10px;margin-bottom:25px}
.business small{display:block;color:#9ca3af;font-size:11px}
.business strong{font-size:14px}
.title{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;padding:0 15px;margin:20px 0 10px}
.nav{display:flex;align-items:center;gap:12px;padding:12px 15px;color:#d1d5db;text-decoration:none;border-radius:8px;font-size:14px;margin-bottom:5px}
.nav:hover,.active{background:#1f2937;color:#22c55e}
.logout{color:#f87171;margin-top:30px}
.main{margin-left:250px;width:calc(100% - 250px)}
.top{height:75px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;padding:0 35px}
.top h2{font-size:22px}
.top p{font-size:13px;color:#6b7280}
.user{display:flex;align-items:center;gap:12px}
.avatar{width:42px;height:42px;border-radius:50%;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;font-weight:700}
.content{padding:30px}
.welcome{background:#111827;color:#fff;padding:25px;border-radius:12px;margin-bottom:25px}
.welcome p{margin-top:6px;color:#d1d5db}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:25px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px}
.card small{display:block;color:#6b7280;margin-bottom:10px}
.card h2{font-size:25px}
.green{color:#16a34a}
.orange{color:#ea580c}
.blue{color:#2563eb}
.quick{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:25px}
.action{background:#fff;border:1px solid #e5e7eb;border-radius:12px;text-align:center;padding:30px;text-decoration:none;color:#111827;font-weight:700;transition:.2s}
.action:hover{background:#16a34a;color:#fff}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:25px}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.head{display:flex;justify-content:space-between;align-items:center;padding:18px 22px;border-bottom:1px solid #e5e7eb}
.head a{text-decoration:none;color:#16a34a;font-size:12px;font-weight:700}
table{width:100%;border-collapse:collapse}
th{padding:13px 18px;text-align:left;font-size:11px;background:#f9fafb;color:#6b7280;text-transform:uppercase}
td{padding:15px 18px;border-top:1px solid #f3f4f6;font-size:13px}
.status{padding:5px 10px;border-radius:20px;font-size:11px;font-weight:700}
.paid{background:#dcfce7;color:#15803d}
.partial{background:#fef3c7;color:#b45309}
.unpaid{background:#fee2e2;color:#b91c1c}
.summary{padding:20px}
.item{display:flex;justify-content:space-between;padding:14px 0;border-bottom:1px solid #eee}
.item:last-child{border:none}
@media(max-width:1100px){.stats,.quick{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}
@media(max-width:700px){.sidebar{display:none}.main{margin:0;width:100%}.stats,.quick{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
<div class="logo">SME<span>360</span></div>
<div class="business"><small>BUSINESS</small><strong><?=htmlspecialchars($business_name)?></strong></div>
<div class="title">Cashier</div>
<a class="nav active" href="cashier-dashboard.php">📊 Dashboard</a>
<a class="nav" href="cashier-sales.php">🛒 New Sale</a>
<a class="nav" href="invoices.php">🧾 Invoices</a>
<a class="nav logout" href="logout.php">↪ Logout</a>
</aside>

<main class="main">
<div class="top">
<div>
<h2>Cashier Dashboard</h2>
<p><?=date("l, d F Y")?></p>
</div>
<div class="user">
<div class="avatar"><?=strtoupper($full_name[0])?></div>
<div>
<strong><?=htmlspecialchars($full_name)?></strong><br>
<small><?=htmlspecialchars($role_name)?></small>
</div>
</div>
</div>

<div class="content">

<div class="welcome">
<h2>Welcome back, <?=htmlspecialchars($full_name)?>!</h2>
<p>Ready to serve customers? Here's your sales activity for today.</p>
</div>

<div class="stats">
<div class="card"><small>Today's Sales</small><h2 class="green"><?=money($todaySales)?></h2><p><?=$todayTransactions?> transaction(s)</p></div>
<div class="card"><small>Cash Sales</small><h2 class="blue"><?=money($cashSales)?></h2></div>
<div class="card"><small>Credit Sales</small><h2 class="orange"><?=money($creditSales)?></h2></div>
<div class="card"><small>Mobile/Card</small><h2><?=money(($payments["mobile_money"]??0)+($payments["debit_card"]??0)+($payments["credit_card"]??0))?></h2></div>
</div>

<div class="grid">
    <div class="box">
<div class="head">
<h3>My Recent Sales</h3>
<a href="sales.php">View All</a>
</div>

<?php if ($recentSales): ?>

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

<?php foreach ($recentSales as $sale):

$class = "paid";
if ($sale["payment_status"] == "partial") $class = "partial";
elseif ($sale["payment_status"] == "unpaid") $class = "unpaid";

?>

<tr>

<td>
<strong><?=htmlspecialchars($sale["sale_id"])?></strong><br>
<small><?=date("d M Y H:i", strtotime($sale["sale_date"]))?></small>
</td>

<td><?=htmlspecialchars($sale["customer_name"])?></td>

<td><strong><?=money($sale["total_amount"])?></strong></td>

<td><?=ucwords(str_replace("_", " ", $sale["payment_method"]))?></td>

<td>
<span class="status <?=$class?>">
<?=ucfirst($sale["payment_status"])?>
</span>
</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php else: ?>

<div style="padding:40px;text-align:center;color:#9ca3af">
No sales recorded yet.
</div>

<?php endif; ?>

</div>

<div class="box">

<div class="head">
<h3>Today's Payment Summary</h3>
</div>

<div class="summary">

<div class="item">
<span>Cash</span>
<strong><?=money($payments["cash"]??0)?></strong>
</div>

<div class="item">
<span>Mobile Money</span>
<strong><?=money($payments["mobile_money"]??0)?></strong>
</div>

<div class="item">
<span>Debit Card</span>
<strong><?=money($payments["debit_card"]??0)?></strong>
</div>

<div class="item">
<span>Credit Card</span>
<strong><?=money($payments["credit_card"]??0)?></strong>
</div>

<div class="item">
<span>Bank Transfer</span>
<strong><?=money($payments["bank_transfer"]??0)?></strong>
</div>

<div class="item">
<span>Credit</span>
<strong class="orange"><?=money($creditSales)?></strong>
</div>

<div class="item">
<span><strong>Total Sales</strong></span>
<strong class="green"><?=money($todaySales)?></strong>
</div>

</div>

</div>

</div>

</div>

</main>

</div>

</body>

</html>