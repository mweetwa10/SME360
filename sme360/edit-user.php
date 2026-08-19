<?php
require_once "auth_check.php";
require_once "db.php";

$business_id = $_SESSION["business_id"];

$message = "";
$error = "";

$user_id = trim($_GET["id"] ?? "");

if ($user_id === "") {
    header("Location: users.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| FETCH USER
|--------------------------------------------------------------------------
*/

$userQuery = $pdo->prepare("
    SELECT
        u.user_id,
        u.business_id,
        u.role_id,
        u.full_name,
        u.username,
        u.email,
        u.phone,
        u.status,
        r.role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE u.user_id = :user_id
    AND u.business_id = :business_id
    LIMIT 1
");

$userQuery->execute([
    ":user_id" => $user_id,
    ":business_id" => $business_id
]);

$user = $userQuery->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: users.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| FETCH ROLES
|--------------------------------------------------------------------------
*/

$roleQuery = $pdo->query("
    SELECT
        role_id,
        role_name,
        description
    FROM roles
    ORDER BY role_name ASC
");

$roles = $roleQuery->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| UPDATE USER
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_user"])) {

    $full_name = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $role_id = trim($_POST["role_id"] ?? "");
    $status = $_POST["status"] ?? "active";
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($full_name === "") {
        $error = "Please enter the user's full name.";

    } elseif ($username === "") {
        $error = "Please enter a username.";

    } elseif ($role_id === "") {
        $error = "Please select a user role.";

    } elseif (!in_array($status, ["active", "inactive"], true)) {
        $error = "Invalid user status.";

    } elseif ($password !== "" && strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";

    } elseif ($password !== "" && $password !== $confirm_password) {
        $error = "Passwords do not match.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | CHECK USERNAME
        |--------------------------------------------------------------------------
        | Username must not belong to another user.
        */

        $usernameCheck = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE username = :username
            AND user_id != :user_id
            LIMIT 1
        ");

        $usernameCheck->execute([
            ":username" => $username,
            ":user_id" => $user_id
        ]);

        if ($usernameCheck->fetch()) {

            $error = "That username is already being used.";

        } else {

            /*
            |--------------------------------------------------------------------------
            | CHECK ROLE
            |--------------------------------------------------------------------------
            */

            $roleCheck = $pdo->prepare("
                SELECT role_id
                FROM roles
                WHERE role_id = :role_id
                LIMIT 1
            ");

            $roleCheck->execute([
                ":role_id" => $role_id
            ]);

            if (!$roleCheck->fetch()) {

                $error = "Invalid user role.";

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | UPDATE WITH OR WITHOUT PASSWORD
                    |--------------------------------------------------------------------------
                    */

                    if ($password !== "") {

                        $password_hash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $update = $pdo->prepare("
                            UPDATE users
                            SET
                                role_id = :role_id,
                                full_name = :full_name,
                                username = :username,
                                email = :email,
                                phone = :phone,
                                password_hash = :password_hash,
                                status = :status
                            WHERE user_id = :user_id
                            AND business_id = :business_id
                        ");

                        $update->execute([
                            ":role_id" => $role_id,
                            ":full_name" => $full_name,
                            ":username" => $username,
                            ":email" => $email !== "" ? $email : null,
                            ":phone" => $phone !== "" ? $phone : null,
                            ":password_hash" => $password_hash,
                            ":status" => $status,
                            ":user_id" => $user_id,
                            ":business_id" => $business_id
                        ]);

                    } else {

                        $update = $pdo->prepare("
                            UPDATE users
                            SET
                                role_id = :role_id,
                                full_name = :full_name,
                                username = :username,
                                email = :email,
                                phone = :phone,
                                status = :status
                            WHERE user_id = :user_id
                            AND business_id = :business_id
                        ");

                        $update->execute([
                            ":role_id" => $role_id,
                            ":full_name" => $full_name,
                            ":username" => $username,
                            ":email" => $email !== "" ? $email : null,
                            ":phone" => $phone !== "" ? $phone : null,
                            ":status" => $status,
                            ":user_id" => $user_id,
                            ":business_id" => $business_id
                        ]);
                    }

                    header("Location: users.php?updated=1");
                    exit;

                } catch (PDOException $e) {

                    $error = "Unable to update user. Please try again.";

                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | KEEP ENTERED VALUES AFTER ERROR
    |--------------------------------------------------------------------------
    */

    $user["full_name"] = $full_name;
    $user["username"] = $username;
    $user["email"] = $email;
    $user["phone"] = $phone;
    $user["role_id"] = $role_id;
    $user["status"] = $status;
}

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Edit User | SME360</title>

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

.back-link {
    display: inline-block;
    margin-bottom: 15px;
    color: #2563eb;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.form-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    max-width: 900px;
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
select {
    padding: 12px;
    border: 1px solid #d1d5db;
    border-radius: 7px;
    font-size: 14px;
    outline: none;
}

input:focus,
select:focus {
    border-color: #22c55e;
}

.help-text {
    color: #9ca3af;
    font-size: 11px;
    margin-top: 5px;
}

.message {
    padding: 13px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
    max-width: 900px;
}

.error {
    background: #fee2e2;
    color: #991b1b;
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
    font-size: 13px;
}

.cancel-button:hover {
    background: #d1d5db;
}

.section-title {
    grid-column: 1 / -1;
    font-size: 15px;
    font-weight: 700;
    padding-bottom: 10px;
    border-bottom: 1px solid #e5e7eb;
    margin-top: 5px;
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

    .form-group.full,
    .section-title {
        grid-column: auto;
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

    <a href="products.php" class="nav-link">
        <span>□</span>
        <span>Products</span>
    </a>

    <a href="inventory.php" class="nav-link">
        <span>◫</span>
        <span>Inventory</span>
    </a>

    <a href="sales.php" class="nav-link">
        <span>$</span>
        <span>Sales</span>
    </a>

    <a href="customers.php" class="nav-link">
        <span>♙</span>
        <span>Customers</span>
    </a>

    <div class="nav-title">Finance</div>

    <a href="expenses.php" class="nav-link">
        <span>↓</span>
        <span>Expenses</span>
    </a>

    <a href="invoices.php" class="nav-link">
        <span>▤</span>
        <span>Invoices</span>
    </a>

    <a href="payments.php" class="nav-link">
        <span>✓</span>
        <span>Payments</span>
    </a>

    <div class="nav-title">Management</div>

    <a href="reports.php" class="nav-link">
        <span>◴</span>
        <span>Reports</span>
    </a>

    <a href="users.php" class="nav-link active">
        <span>♙</span>
        <span>Users</span>
    </a>

    <a href="settings.php" class="nav-link">
        <span>⚙</span>
        <span>Settings</span>
    </a>

    <a href="logout.php" class="nav-link logout">
        <span>↪</span>
        <span>Logout</span>
    </a>

</aside>

<main class="main">

    <header class="topbar">

        <h1>Edit User</h1>

        <div class="user">
            Welcome,
            <strong><?= e($_SESSION["full_name"]) ?></strong>
        </div>

    </header>

    <div class="content">

        <a href="users.php" class="back-link">
            ← Back to Users
        </a>

        <div class="page-header">

            <h2>Edit User</h2>

            <p>
                Update the user's account information, role, status, or password.
            </p>

        </div>

        <?php if ($error !== ""): ?>

            <div class="message error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <div class="form-card">

            <form method="POST" action="">

                <div class="form-grid">

                    <div class="section-title">
                        User Information
                    </div>

                    <div class="form-group">

                        <label>User ID</label>

                        <input
                            type="text"
                            value="<?= e($user["user_id"]) ?>"
                            readonly
                        >

                    </div>

                    <div class="form-group">

                        <label>Full Name *</label>

                        <input
                            type="text"
                            name="full_name"
                            value="<?= e($user["full_name"]) ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Username *</label>

                        <input
                            type="text"
                            name="username"
                            value="<?= e($user["username"]) ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Role *</label>

                        <select name="role_id" required>

                            <option value="">
                                Select Role
                            </option>

                            <?php foreach ($roles as $role): ?>

                                <option
                                    value="<?= e($role["role_id"]) ?>"
                                    <?= (string) $user["role_id"] === (string) $role["role_id"] ? "selected" : "" ?>
                                >

                                    <?= e($role["role_name"]) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Email</label>

                        <input
                            type="email"
                            name="email"
                            value="<?= e($user["email"]) ?>"
                        >

                    </div>

                    <div class="form-group">

                        <label>Phone</label>

                        <input
                            type="text"
                            name="phone"
                            value="<?= e($user["phone"]) ?>"
                        >

                    </div>

                    <div class="form-group">

                        <label>Status *</label>

                        <select name="status" required>

                            <option
                                value="active"
                                <?= $user["status"] === "active" ? "selected" : "" ?>
                            >
                                Active
                            </option>

                            <option
                                value="inactive"
                                <?= $user["status"] === "inactive" ? "selected" : "" ?>
                            >
                                Inactive
                            </option>

                        </select>

                    </div>

                    <div class="section-title">
                        Change Password
                    </div>

                    <div class="form-group">

                        <label>New Password</label>

                        <input
                            type="password"
                            name="password"
                            placeholder="Leave blank to keep current password"
                        >

                        <span class="help-text">
                            Only enter a password if you want to change it.
                            Minimum 6 characters.
                        </span>

                    </div>

                    <div class="form-group">

                        <label>Confirm New Password</label>

                        <input
                            type="password"
                            name="confirm_password"
                            placeholder="Repeat new password"
                        >

                    </div>

                </div>

                <div class="form-actions">

                    <button
                        type="submit"
                        name="update_user"
                        class="submit-button"
                    >
                        Save Changes
                    </button>

                    <a
                        href="users.php"
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