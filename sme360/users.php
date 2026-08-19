<?php
require_once "auth_check.php";
require_once "db.php";

$business_id = $_SESSION["business_id"];

$message = "";
$error = "";

/* =========================
   GENERATE USER ID
========================= */
function generateUserID($pdo)
{
    $prefix = "USR";

    $query = $pdo->query("
        SELECT user_id
        FROM users
        WHERE user_id LIKE 'USR%'
        ORDER BY user_id DESC
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

/* =========================
   SUCCESS MESSAGES
========================= */
if (isset($_GET["updated"]) && $_GET["updated"] === "1") {
    $message = "User updated successfully.";
}

if (isset($_GET["status"]) && $_GET["status"] === "1") {
    $message = "User status updated successfully.";
}

/* =========================
   ADD USER
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_user"])) {

    $full_name = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $role_id = trim($_POST["role_id"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($full_name === "") {

        $error = "Please enter the user's full name.";

    } elseif ($username === "") {

        $error = "Please enter a username.";

    } elseif ($role_id === "") {

        $error = "Please select a role.";

    } elseif ($password === "") {

        $error = "Please enter a password.";

    } elseif (strlen($password) < 6) {

        $error = "Password must be at least 6 characters long.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {

        try {

            /* =========================
               CHECK ROLE
            ========================= */
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

                $error = "Invalid role selected.";

            } else {

                /* =========================
                   CHECK USERNAME
                ========================= */
                $usernameCheck = $pdo->prepare("
                    SELECT user_id
                    FROM users
                    WHERE username = :username
                    AND business_id = :business_id
                    LIMIT 1
                ");

                $usernameCheck->execute([
                    ":username" => $username,
                    ":business_id" => $business_id
                ]);

                if ($usernameCheck->fetch()) {

                    $error = "That username is already in use.";

                } else {

                    /* =========================
                       CHECK EMAIL
                    ========================= */
                    if ($email !== "") {

                        $emailCheck = $pdo->prepare("
                            SELECT user_id
                            FROM users
                            WHERE email = :email
                            AND business_id = :business_id
                            LIMIT 1
                        ");

                        $emailCheck->execute([
                            ":email" => $email,
                            ":business_id" => $business_id
                        ]);

                        if ($emailCheck->fetch()) {
                            $error = "That email address is already in use.";
                        }
                    }

                    if ($error === "") {

                        $user_id = generateUserID($pdo);

                        $password_hash = password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        );

                        $insert = $pdo->prepare("
                            INSERT INTO users (
                                user_id,
                                business_id,
                                role_id,
                                full_name,
                                username,
                                email,
                                phone,
                                password_hash,
                                status
                            )
                            VALUES (
                                :user_id,
                                :business_id,
                                :role_id,
                                :full_name,
                                :username,
                                :email,
                                :phone,
                                :password_hash,
                                'active'
                            )
                        ");

                        $insert->execute([
                            ":user_id" => $user_id,
                            ":business_id" => $business_id,
                            ":role_id" => $role_id,
                            ":full_name" => $full_name,
                            ":username" => $username,
                            ":email" => $email !== "" ? $email : null,
                            ":phone" => $phone !== "" ? $phone : null,
                            ":password_hash" => $password_hash
                        ]);

                        $message = "User added successfully.";
                    }
                }
            }

        } catch (PDOException $e) {

            $error = "Unable to add user. Please try again.";
        }
    }
}

/* =========================
   TOGGLE USER STATUS
========================= */
if (isset($_GET["toggle"]) && $_GET["toggle"] !== "") {

    $user_id = trim($_GET["toggle"]);

    /*
     * Prevent the currently logged-in user
     * from accidentally deactivating themselves.
     */
    if ($user_id === $_SESSION["user_id"]) {

        $error = "You cannot deactivate your own account.";

    } else {

        try {

            $toggleQuery = $pdo->prepare("
                UPDATE users
                SET status = CASE
                    WHEN status = 'active' THEN 'inactive'
                    ELSE 'active'
                END
                WHERE user_id = :user_id
                AND business_id = :business_id
            ");

            $toggleQuery->execute([
                ":user_id" => $user_id,
                ":business_id" => $business_id
            ]);

            header("Location: users.php?status=1");
            exit;

        } catch (PDOException $e) {

            $error = "Unable to update user status.";
        }
    }
}

/* =========================
   GET ROLES
========================= */
$roleQuery = $pdo->query("
    SELECT
        role_id,
        role_name,
        description
    FROM roles
    ORDER BY role_name ASC
");

$roles = $roleQuery->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   SEARCH AND FILTERS
========================= */
$search = trim($_GET["search"] ?? "");
$filter_role = trim($_GET["role"] ?? "");
$filter_status = trim($_GET["status"] ?? "");

/* =========================
   GET USERS
========================= */
$sql = "
    SELECT
        u.user_id,
        u.role_id,
        u.full_name,
        u.username,
        u.email,
        u.phone,
        u.status,
        u.last_login,
        u.created_at,
        r.role_name
    FROM users u
    LEFT JOIN roles r
        ON u.role_id = r.role_id
    WHERE u.business_id = :business_id
";

$params = [
    ":business_id" => $business_id
];

/* Search */
if ($search !== "") {

    $sql .= "
        AND (
            u.user_id LIKE :search
            OR u.full_name LIKE :search
            OR u.username LIKE :search
            OR u.email LIKE :search
            OR u.phone LIKE :search
        )
    ";

    $params[":search"] = "%" . $search . "%";
}

/* Role filter */
if ($filter_role !== "") {

    $sql .= "
        AND u.role_id = :role_id
    ";

    $params[":role_id"] = $filter_role;
}

/* Status filter */
if ($filter_status !== "" && in_array($filter_status, ["active", "inactive"])) {

    $sql .= "
        AND u.status = :status
    ";

    $params[":status"] = $filter_status;
}

$sql .= "
    ORDER BY u.created_at DESC
";

$userQuery = $pdo->prepare($sql);
$userQuery->execute($params);

$users = $userQuery->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   FORMAT DATE
========================= */
function formatDateTime($date)
{
    if (!$date) {
        return "Never";
    }

    return date("d M Y, H:i", strtotime($date));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users | SME360</title>

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

.form-card h3 {
    font-size: 17px;
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
    min-width: 160px;
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

.user-name {
    font-weight: 600;
    color: #111827;
}

.user-id {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 3px;
}

.role {
    background: #dbeafe;
    color: #1d4ed8;
    padding: 5px 9px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 600;
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

.action-link.danger {
    color: #dc2626;
}

.action-link.success-link {
    color: #16a34a;
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

@media (max-width: 900px) {
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
}
</style>

<script>
function toggleForm() {
    const form = document.getElementById("addUserForm");
    form.classList.toggle("show");
}
</script>
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

    <a href="users.php" class="nav-link active">
        <span>♙</span>
        <span>Users</span>
    </a>

</aside>

<main class="main">

    <header class="topbar">

        <div>
            <h1>Users</h1>
        </div>

        <div class="user">
            Welcome, <strong><?= htmlspecialchars($_SESSION["full_name"]) ?></strong>
        </div>

    </header>

    <div class="content">

        <div class="page-header">

            <div>
                <h2>Business Users</h2>
                <p>Manage the users who have access to your business account.</p>
            </div>

            <button class="add-button" onclick="toggleForm()">
                + Add User
            </button>

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

        <div class="form-card" id="addUserForm">

            <h3>Add New User</h3>

            <br>

            <form method="POST" action="">

                <div class="form-grid">

                    <div class="form-group">

                        <label>Full Name *</label>

                        <input
                            type="text"
                            name="full_name"
                            placeholder="Enter full name"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Username *</label>

                        <input
                            type="text"
                            name="username"
                            placeholder="Enter username"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Email</label>

                        <input
                            type="email"
                            name="email"
                            placeholder="Enter email address"
                        >

                    </div>

                    <div class="form-group">

                        <label>Phone</label>

                        <input
                            type="text"
                            name="phone"
                            placeholder="Enter phone number"
                        >

                    </div>

                    <div class="form-group">

                        <label>Role *</label>

                        <select name="role_id" required>

                            <option value="">
                                Select Role
                            </option>

                            <?php foreach ($roles as $role): ?>

                                <option value="<?= htmlspecialchars($role["role_id"]) ?>">

                                    <?= htmlspecialchars($role["role_name"]) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="form-group">

                        <label>Password *</label>

                        <input
                            type="password"
                            name="password"
                            placeholder="Minimum 6 characters"
                            minlength="6"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label>Confirm Password *</label>

                        <input
                            type="password"
                            name="confirm_password"
                            placeholder="Confirm password"
                            minlength="6"
                            required
                        >

                    </div>

                </div>

                <div class="form-actions">

                    <button
                        type="submit"
                        name="add_user"
                        class="submit-button"
                    >
                        Save User
                    </button>

                    <button
                        type="button"
                        class="cancel-button"
                        onclick="toggleForm()"
                    >
                        Cancel
                    </button>

                </div>

            </form>

        </div>

        <div class="filters">

            <form method="GET" class="filter-form">

                <input
                    type="text"
                    name="search"
                    placeholder="Search by name, username, email or phone"
                    value="<?= htmlspecialchars($search) ?>"
                >

                <select name="role">

                    <option value="">
                        All Roles
                    </option>

                    <?php foreach ($roles as $role): ?>

                        <option
                            value="<?= htmlspecialchars($role["role_id"]) ?>"
                            <?= $filter_role == $role["role_id"] ? "selected" : "" ?>
                        >
                            <?= htmlspecialchars($role["role_name"]) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

                <select name="status">

                    <option value="">
                        All Status
                    </option>

                    <option
                        value="active"
                        <?= $filter_status === "active" ? "selected" : "" ?>
                    >
                        Active
                    </option>

                    <option
                        value="inactive"
                        <?= $filter_status === "inactive" ? "selected" : "" ?>
                    >
                        Inactive
                    </option>

                </select>

                <button
                    type="submit"
                    class="filter-button"
                >
                    Search
                </button>

                <a
                    href="users.php"
                    class="clear-button"
                >
                    Clear
                </a>

            </form>

        </div>

        <div class="table-card">

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>User</th>
                            <th>Role</th>
                            <th>Contact</th>
                            <th>Last Login</th>
                            <th>Status</th>
                            <th>Action</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (empty($users)): ?>

                        <tr>

                            <td
                                colspan="6"
                                class="empty"
                            >
                                No users found.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($users as $user): ?>

                            <tr>

                                <td>

                                    <div class="user-name">
                                        <?= htmlspecialchars($user["full_name"]) ?>
                                    </div>

                                    <div class="user-id">
                                        <?= htmlspecialchars($user["user_id"]) ?>
                                        ·
                                        <?= htmlspecialchars($user["username"]) ?>
                                    </div>

                                </td>

                                <td>

                                    <span class="role">

                                        <?= htmlspecialchars(
                                            $user["role_name"] ?? "Unknown"
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <?php if (!empty($user["email"])): ?>

                                        <div>
                                            <?= htmlspecialchars($user["email"]) ?>
                                        </div>

                                    <?php endif; ?>

                                    <?php if (!empty($user["phone"])): ?>

                                        <small>
                                            <?= htmlspecialchars($user["phone"]) ?>
                                        </small>

                                    <?php endif; ?>

                                    <?php if (empty($user["email"]) && empty($user["phone"])): ?>

                                        <span style="color:#9ca3af;">
                                            No contact details
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?= formatDateTime($user["last_login"]) ?>

                                </td>

                                <td>

                                    <span class="status status-<?= htmlspecialchars($user["status"]) ?>">

                                        <?= ucfirst(htmlspecialchars($user["status"])) ?>

                                    </span>

                                </td>

                                <td>

                                    <a
                                        href="edit-user.php?id=<?= urlencode($user["user_id"]) ?>"
                                        class="action-link"
                                    >
                                        Edit
                                    </a>

                                    <span class="action-sep">|</span>

                                    <?php if ($user["user_id"] !== $_SESSION["user_id"]): ?>

                                        <a
                                            href="users.php?toggle=<?= urlencode($user["user_id"]) ?>"
                                            class="action-link <?= $user["status"] === "active" ? "danger" : "success-link" ?>"
                                            onclick="return confirm('Are you sure you want to <?= $user["status"] === "active" ? "deactivate" : "activate" ?> this user?');"
                                        >
                                            <?= $user["status"] === "active" ? "Deactivate" : "Activate" ?>
                                        </a>

                                    <?php else: ?>

                                        <span style="color:#9ca3af;font-size:12px;">
                                            Current User
                                        </span>

                                    <?php endif; ?>

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