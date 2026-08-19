<?php
session_start();
require_once "db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        $error = "Please enter your email and password.";
    } else {
        $query = $pdo->prepare("
            SELECT u.user_id, u.business_id, u.role_id, u.full_name, u.username,
                   u.email, u.password_hash, u.status, r.role_name,
                   b.business_name, b.status AS business_status
            FROM users u
            INNER JOIN roles r ON u.role_id = r.role_id
            INNER JOIN businesses b ON u.business_id = b.business_id
            WHERE u.email = :email
            LIMIT 1
        ");
        $query->execute([":email" => $email]);
        $user = $query->fetch();

        if (!$user) {
            $error = "Invalid email or password.";
        } elseif ($user["status"] !== "active") {
            $error = "Your account has been deactivated. Please contact the business owner.";
        } elseif ($user["business_status"] !== "active") {
            $error = "This business account is currently inactive.";
        } elseif (!password_verify($password, $user["password_hash"])) {
            $error = "Invalid email or password.";
        } else {
            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["user_id"];
            $_SESSION["business_id"] = $user["business_id"];
            $_SESSION["role_id"] = $user["role_id"];
            $_SESSION["role_name"] = $user["role_name"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["business_name"] = $user["business_name"];
            $_SESSION["login_time"] = time();

            $updateLogin = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = :user_id");
            $updateLogin->execute([":user_id" => $user["user_id"]]);

            $role = strtolower(trim($user["role_name"]));

            switch ($role) {
                case "owner/admin":
                    $redirect = "dashboard.php";
                    break;
                case "cashier":
                    $redirect = "cashier-dashboard.php";
                    break;
                case "inventory staff":
                    $redirect = "inventory-dashboard.php";
                    break;
                default:
                    $redirect = "dashboard.php";
            }

            header("Location: " . $redirect);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SME360</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            min-height: 100vh;
            background: #f4f7fb;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .login-header {
            background: #111827;
            color: #ffffff;
            text-align: center;
            padding: 35px 30px;
        }
        .logo { font-size: 30px; font-weight: 700; letter-spacing: 1px; margin-bottom: 10px; }
        .logo span { color: #22c55e; }
        .login-header p { color: #d1d5db; font-size: 14px; line-height: 1.5; }
        .login-form { padding: 35px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px; }
        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: 0.2s;
        }
        input:focus {
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.12);
        }
        .error-message {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }
        .login-button {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: #22c55e;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }
        .login-button:hover { background: #16a34a; transform: translateY(-1px); }
        .register-link { text-align: center; margin-top: 25px; font-size: 14px; color: #6b7280; }
        .register-link a { color: #16a34a; text-decoration: none; font-weight: 600; }
        .register-link a:hover { text-decoration: underline; }
        .login-footer { text-align: center; padding: 0 35px 30px; color: #9ca3af; font-size: 12px; }
        @media (max-width: 500px) {
            .login-form { padding: 25px; }
            .login-header { padding: 30px 20px; }
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="login-header">
        <div class="logo">SME<span>360</span></div>
        <p>Sign in to manage your business</p>
    </div>
    <form class="login-form" method="POST" action="">
        <?php if ($error !== ""): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div class="form-group">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                placeholder="Enter your email"
                value="<?= htmlspecialchars($_POST["email"] ?? "") ?>"
                autocomplete="email"
                required
            >
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >
        </div>
        <button type="submit" class="login-button">Sign In</button>
        <div class="register-link">
            Don't have a SME360 account?
            <a href="register.php">Create a business account</a>
        </div>
    </form>
    <div class="login-footer">SME360 &copy; <?= date("Y") ?></div>
</div>
</body>
</html>