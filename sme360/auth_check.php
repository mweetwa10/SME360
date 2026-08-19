```php
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION["user_id"];

$query = $pdo->prepare("
    SELECT
        u.user_id,
        u.business_id,
        u.role_id,
        u.full_name,
        u.username,
        u.status AS user_status,
        r.role_name,
        b.business_name,
        b.status AS business_status
    FROM users u
    INNER JOIN roles r
        ON u.role_id = r.role_id
    INNER JOIN businesses b
        ON u.business_id = b.business_id
    WHERE u.user_id = :user_id
    LIMIT 1
");

$query->execute([
    ":user_id" => $user_id
]);

$user = $query->fetch();

if (!$user) {
    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;
}

if ($user["user_status"] !== "active") {
    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;
}

if ($user["business_status"] !== "active") {
    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;
}

$_SESSION["user_id"] = $user["user_id"];
$_SESSION["business_id"] = $user["business_id"];
$_SESSION["role_id"] = $user["role_id"];
$_SESSION["role_name"] = $user["role_name"];
$_SESSION["full_name"] = $user["full_name"];
$_SESSION["username"] = $user["username"];
$_SESSION["business_name"] = $user["business_name"];
