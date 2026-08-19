<?php
require_once "auth_check.php";
require_once "db.php";

if($_SESSION["role_name"]!="Inventory"){
    header("Location:login.php");
    exit;
}

$business_id=$_SESSION["business_id"];
$full_name=$_SESSION["full_name"];
$role_name=$_SESSION["role_name"];

$q=$pdo->prepare("SELECT business_name FROM businesses WHERE business_id=?");
$q->execute([$business_id]);
$business_name=$q->fetchColumn()?:'My Business';

$q=$pdo->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND status='active'");
$q->execute([$business_id]);
$totalProducts=$q->fetchColumn();

$q=$pdo->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND product_type='product' AND status='active' AND current_stock<=minimum_stock");
$q->execute([$business_id]);
$lowStock=$q->fetchColumn();

$q=$pdo->prepare("SELECT COUNT(*) FROM products WHERE business_id=? AND product_type='product' AND status='active' AND current_stock<=0");
$q->execute([$business_id]);
$outOfStock=$q->fetchColumn();

$q=$pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE business_id=? AND movement_type='stock_in' AND DATE(created_at)=CURDATE()");
$q->execute([$business_id]);
$stockInToday=$q->fetchColumn();

$q=$pdo->prepare("
SELECT
sm.created_at,
sm.product_id,
p.product_name,
sm.quantity,
sm.movement_type
FROM stock_movements sm
JOIN products p ON sm.product_id=p.product_id
WHERE sm.business_id=?
ORDER BY sm.created_at DESC
LIMIT 8");
$q->execute([$business_id]);
$movements=$q->fetchAll();

$q=$pdo->prepare("
SELECT
product_name,
current_stock,
minimum_stock
FROM products
WHERE business_id=?
AND product_type='product'
AND status='active'
AND current_stock<=minimum_stock
ORDER BY current_stock ASC
LIMIT 8");
$q->execute([$business_id]);
$lowProducts=$q->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inventory Dashboard | SME360</title>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;background:#f4f7fb;color:#1f2937}
.layout{display:flex;min-height:100vh}
.sidebar{width:250px;background:#111827;color:#fff;position:fixed;top:0;bottom:0;padding:25px 18px}
.logo{font-size:28px;font-weight:700;padding:0 15px 30px}
.logo span{color:#22c55e}
.business{background:#1f2937;padding:15px;border-radius:10px;margin-bottom:25px}
.business small{display:block;color:#9ca3af;font-size:11px}
.title{font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:700;padding:0 15px;margin:20px 0 10px}
.nav{display:flex;gap:12px;padding:12px 15px;border-radius:8px;color:#d1d5db;text-decoration:none;font-size:14px;margin-bottom:5px}
.nav:hover,.active{background:#1f2937;color:#22c55e}
.logout{margin-top:30px;color:#f87171}
.main{margin-left:250px;width:calc(100% - 250px)}
.top{height:75px;background:#fff;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;padding:0 35px}
.avatar{width:42px;height:42px;border-radius:50%;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;font-weight:bold}
.user{display:flex;align-items:center;gap:12px}
.content{padding:30px}
.welcome{background:#111827;color:#fff;padding:25px;border-radius:12px;margin-bottom:25px}
.welcome p{margin-top:6px;color:#d1d5db}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:25px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:22px}
.card small{display:block;color:#6b7280;margin-bottom:10px}
.card h2{font-size:25px}
.green{color:#16a34a}
.red{color:#dc2626}
.orange{color:#ea580c}
.blue{color:#2563eb}
.quick{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:25px}
.action{background:#fff;border:1px solid #e5e7eb;border-radius:12px;text-decoration:none;color:#111827;padding:30px;text-align:center;font-weight:bold}
.action:hover{background:#16a34a;color:#fff}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:25px}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.head{display:flex;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #e5e7eb}
.head a{text-decoration:none;color:#16a34a;font-size:12px;font-weight:bold}
table{width:100%;border-collapse:collapse}
th{padding:13px 18px;text-align:left;font-size:11px;background:#f9fafb;color:#6b7280;text-transform:uppercase}
td{padding:15px 18px;border-top:1px solid #f3f4f6;font-size:13px}
.in{color:#16a34a;font-weight:bold}
.out{color:#dc2626;font-weight:bold}
.stock{padding:20px}
.item{display:flex;justify-content:space-between;padding:15px 0;border-bottom:1px solid #eee}
.item:last-child{border:none}
@media(max-width:1100px){.stats,.quick{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}
@media(max-width:700px){.sidebar{display:none}.main{margin:0;width:100%}.stats,.quick{grid-template-columns:1fr}}
</style>
</head>

<body>

<div class="layout">

<aside class="sidebar">

<div class="logo">SME<span>360</span></div>

<div class="business">
<small>BUSINESS</small>
<strong><?=htmlspecialchars($business_name)?></strong>
</div>

<div class="title">Inventory</div>

<a class="nav active" href="dashboard.php">📊 Dashboard</a>
<a class="nav" href="inventory.php">📦 Inventory</a>
<a class="nav" href="products.php">🛒 Products</a>
<a class="nav" href="categories.php">🏷 Categories</a>
<a class="nav" href="suppliers.php">🚚 Suppliers</a>
<a class="nav" href="stock-in.php">📥 Stock In</a>
<a class="nav" href="stock-out.php">📤 Stock Out</a>
<a class="nav logout" href="../logout.php">↪ Logout</a>

</aside>

<main class="main">

<div class="top">

<div>
<h2>Inventory Dashboard</h2>
<p><?=date("l, d F Y")?></p>
</div>

<div class="user">
<div class="avatar"><?=strtoupper($full_name[0])?></div>
<div>
<strong><?=htmlspecialchars($full_name)?></strong><br>
<small><?=$role_name?></small>
</div>
</div>

</div>

<div class="content">

<div class="welcome">
<h2>Welcome back, <?=htmlspecialchars($full_name)?>!</h2>
<p>Manage products and stock levels efficiently.</p>
</div>

<div class="stats">

<div class="card">
<small>Total Products</small>
<h2 class="blue"><?=number_format($totalProducts)?></h2>
</div>

<div class="card">
<small>Low Stock</small>
<h2 class="orange"><?=number_format($lowStock)?></h2>
</div>

<div class="card">
<small>Out of Stock</small>
<h2 class="red"><?=number_format($outOfStock)?></h2>
</div>

<div class="card">
<small>Stock In Today</small>
<h2 class="green"><?=number_format($stockInToday,2)?></h2>
</div>

</div>

<div class="quick">

<a class="action" href="products.php">🛒<br><br>Products</a>
<a class="action" href="stock-in.php">📥<br><br>Stock In</a>
<a class="action" href="stock-out.php">📤<br><br>Stock Out</a>
<a class="action" href="suppliers.php">🚚<br><br>Suppliers</a>

</div>

<div class="grid">
    <div class="box">

<div class="head">
<h3>Recent Stock Movements</h3>
<a href="inventory.php">View All</a>
</div>

<?php if($movements):?>

<table>

<thead>

<tr>
<th>Date</th>
<th>Product</th>
<th>Type</th>
<th>Quantity</th>
</tr>

</thead>

<tbody>

<?php foreach($movements as $m):?>

<tr>

<td><?=date("d M Y H:i",strtotime($m["created_at"]))?></td>

<td><?=htmlspecialchars($m["product_name"])?></td>

<td>
<?php if($m["movement_type"]=="stock_in"):?>
<span class="in">Stock In</span>
<?php else:?>
<span class="out">Stock Out</span>
<?php endif;?>
</td>

<td><?=number_format($m["quantity"],2)?></td>

</tr>

<?php endforeach;?>

</tbody>

</table>

<?php else:?>

<div style="padding:40px;text-align:center;color:#9ca3af">
No stock movements found.
</div>

<?php endif;?>

</div>

<div class="box">

<div class="head">
<h3>Low Stock Products</h3>
<a href="inventory.php">View Inventory</a>
</div>

<?php if($lowProducts):?>

<div class="stock">

<?php foreach($lowProducts as $p):?>

<div class="item">

<div>

<strong><?=htmlspecialchars($p["product_name"])?></strong><br>

<small style="color:#6b7280">
Minimum: <?=number_format($p["minimum_stock"],2)?>
</small>

</div>

<div class="red">

<?=number_format($p["current_stock"],2)?>

</div>

</div>

<?php endforeach;?>

</div>

<?php else:?>

<div style="padding:40px;text-align:center;color:#16a34a">

✔ All products are sufficiently stocked.

</div>

<?php endif;?>

</div>

</div>

</div>

</main>

</div>

</body>

</html>