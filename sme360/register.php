<?php

session_start();

require_once "db.php";
require_once "id_generator.php";

$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ============================================================
    // BUSINESS INFORMATION
    // ============================================================

    $business_name = trim($_POST["business_name"] ?? "");
    $business_type = trim($_POST["business_type"] ?? "");
    $business_phone = trim($_POST["business_phone"] ?? "");
    $business_email = trim($_POST["business_email"] ?? "");
    $business_address = trim($_POST["business_address"] ?? "");


    // ============================================================
    // OWNER INFORMATION
    // ============================================================

    $full_name = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");

    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";


    // ============================================================
    // VALIDATION
    // ============================================================

    if ($business_name === "") {
        $errors[] = "Business name is required.";
    }

    if ($business_type === "") {
        $errors[] = "Please select your business type.";
    }

    if ($business_phone === "") {
        $errors[] = "Business phone number is required.";
    }

    if ($full_name === "") {
        $errors[] = "Your full name is required.";
    }

    if ($username === "") {
        $errors[] = "Username is required.";
    }

    if ($email === "") {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if ($password === "") {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }


    // ============================================================
    // CHECK IF EMAIL OR USERNAME ALREADY EXISTS
    // ============================================================

    if (empty($errors)) {

        $checkUser = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE username = :username
               OR email = :email
            LIMIT 1
        ");

        $checkUser->execute([
            ":username" => $username,
            ":email" => $email
        ]);

        if ($checkUser->fetch()) {
            $errors[] = "The username or email is already registered.";
        }
    }


    // ============================================================
    // CREATE BUSINESS AND OWNER ACCOUNT
    // ============================================================

    if (empty($errors)) {

        try {

            // Start database transaction
            $pdo->beginTransaction();


            // ====================================================
            // GENERATE BUSINESS ID
            // ====================================================

            $business_id = generateId(
                $pdo,
                "businesses",
                "business_id",
                "BUS"
            );


            // ====================================================
            // INSERT BUSINESS
            // ====================================================

            $businessQuery = $pdo->prepare("
                INSERT INTO businesses (
                    business_id,
                    business_name,
                    business_type,
                    phone,
                    email,
                    address
                )
                VALUES (
                    :business_id,
                    :business_name,
                    :business_type,
                    :phone,
                    :email,
                    :address
                )
            ");

            $businessQuery->execute([

                ":business_id" => $business_id,

                ":business_name" => $business_name,

                ":business_type" => $business_type,

                ":phone" => $business_phone,

                ":email" => $business_email,

                ":address" => $business_address

            ]);


            // ====================================================
            // GET OWNER ROLE
            // ====================================================

            $roleQuery = $pdo->prepare("
                SELECT role_id
                FROM roles
                WHERE role_name = 'Owner/Admin'
                LIMIT 1
            ");

            $roleQuery->execute();

            $role_id = $roleQuery->fetchColumn();


            if (!$role_id) {

                throw new Exception(
                    "Owner role was not found."
                );

            }


            // ====================================================
            // GENERATE USER ID
            // ====================================================

            $user_id = generateId(
                $pdo,
                "users",
                "user_id",
                "USR"
            );


            // ====================================================
            // HASH PASSWORD
            // ====================================================

            $password_hash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );


            // ====================================================
            // INSERT OWNER USER
            // ====================================================

            $userQuery = $pdo->prepare("
                INSERT INTO users (
                    user_id,
                    business_id,
                    role_id,
                    full_name,
                    username,
                    email,
                    phone,
                    password_hash
                )
                VALUES (
                    :user_id,
                    :business_id,
                    :role_id,
                    :full_name,
                    :username,
                    :email,
                    :phone,
                    :password_hash
                )
            ");

            $userQuery->execute([

                ":user_id" => $user_id,

                ":business_id" => $business_id,

                ":role_id" => $role_id,

                ":full_name" => $full_name,

                ":username" => $username,

                ":email" => $email,

                ":phone" => $phone,

                ":password_hash" => $password_hash

            ]);


            // ====================================================
            // COMMIT TRANSACTION
            // ====================================================

            $pdo->commit();


            // ====================================================
            // CREATE LOGIN SESSION
            // ====================================================

            session_regenerate_id(true);

            $_SESSION["user_id"] = $user_id;

            $_SESSION["business_id"] = $business_id;

            $_SESSION["full_name"] = $full_name;

            $_SESSION["username"] = $username;

            $_SESSION["role_id"] = $role_id;

            $_SESSION["role_name"] = "Owner/Admin";


            // ====================================================
            // REDIRECT TO DASHBOARD
            // ====================================================

            header(
                "Location: dashboard.php"
            );

            exit;


        } catch (Exception $e) {

            // Rollback if anything fails

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] =
                "Registration failed. Please try again.";

            // Development only
            $errors[] =
                $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Create Your SME360 Account
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background:
                #f4f7fb;

            color:
                #1f2937;

            min-height:
                100vh;

            display:
                flex;

            justify-content:
                center;

            align-items:
                center;

            padding:
                40px 20px;

        }

        .register-container {

            width:
                100%;

            max-width:
                900px;

            background:
                #ffffff;

            border-radius:
                16px;

            box-shadow:
                0 15px 40px
                rgba(0, 0, 0, 0.08);

            overflow:
                hidden;

        }

        .header {

            background:
                #111827;

            color:
                white;

            padding:
                35px 40px;

        }

        .logo {

            font-size:
                28px;

            font-weight:
                700;

            letter-spacing:
                1px;

            margin-bottom:
                8px;

        }

        .logo span {

            color:
                #22c55e;

        }

        .header p {

            color:
                #d1d5db;

            font-size:
                14px;

        }

        form {

            padding:
                40px;

        }

        .section-title {

            font-size:
                18px;

            font-weight:
                700;

            color:
                #111827;

            margin-bottom:
                20px;

            padding-bottom:
                10px;

            border-bottom:
                1px solid
                #e5e7eb;

        }

        .form-grid {

            display:
                grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap:
                20px;

            margin-bottom:
                35px;

        }

        .form-group {

            display:
                flex;

            flex-direction:
                column;

        }

        .form-group.full {

            grid-column:
                1 / -1;

        }

        label {

            font-size:
                13px;

            font-weight:
                600;

            margin-bottom:
                7px;

            color:
                #374151;

        }

        input,
        select,
        textarea {

            width:
                100%;

            padding:
                13px 14px;

            border:
                1px solid
                #d1d5db;

            border-radius:
                8px;

            font-size:
                14px;

            outline:
                none;

            transition:
                0.2s;

        }

        input:focus,
        select:focus,
        textarea:focus {

            border-color:
                #22c55e;

            box-shadow:
                0 0 0 3px
                rgba(34, 197, 94, 0.12);

        }

        textarea {

            min-height:
                90px;

            resize:
                vertical;

        }

        .error-box {

            background:
                #fef2f2;

            border:
                1px solid
                #fecaca;

            color:
                #b91c1c;

            padding:
                15px;

            border-radius:
                8px;

            margin-bottom:
                25px;

            font-size:
                14px;

        }

        .error-box ul {

            padding-left:
                20px;

        }

        .submit-area {

            display:
                flex;

            align-items:
                center;

            justify-content:
                space-between;

            gap:
                20px;

            padding-top:
                10px;

        }

        .login-link {

            font-size:
                14px;

            color:
                #6b7280;

        }

        .login-link a {

            color:
                #16a34a;

            text-decoration:
                none;

            font-weight:
                600;

        }

        button {

            background:
                #22c55e;

            color:
                white;

            border:
                none;

            padding:
                14px 28px;

            border-radius:
                8px;

            font-size:
                15px;

            font-weight:
                700;

            cursor:
                pointer;

            transition:
                0.2s;

        }

        button:hover {

            background:
                #16a34a;

            transform:
                translateY(-1px);

        }

        @media (max-width: 700px) {

            .form-grid {

                grid-template-columns:
                    1fr;

            }

            .form-group.full {

                grid-column:
                    auto;

            }

            form {

                padding:
                    25px;

            }

            .header {

                padding:
                    25px;

            }

            .submit-area {

                flex-direction:
                    column;

                align-items:
                    stretch;

            }

            button {

                width:
                    100%;

            }

            .login-link {

                text-align:
                    center;

            }

        }

    </style>

</head>


<body>


<div class="register-container">


    <!-- ========================================================
         HEADER
    ========================================================= -->

    <div class="header">

        <div class="logo">

            SME<span>360</span>

        </div>

        <p>

            Create your business account and start
            managing your business smarter.

        </p>

    </div>


    <!-- ========================================================
         REGISTRATION FORM
    ========================================================= -->

    <form
        method="POST"
        action=""
    >


        <!-- ====================================================
             DISPLAY ERRORS
        ===================================================== -->

        <?php if (!empty($errors)): ?>

            <div class="error-box">

                <strong>
                    Please correct the following:
                </strong>

                <ul>

                    <?php foreach ($errors as $error): ?>

                        <li>
                            <?= htmlspecialchars($error) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>


        <!-- ====================================================
             BUSINESS INFORMATION
        ===================================================== -->

        <div class="section-title">

            Business Information

        </div>


        <div class="form-grid">


            <div class="form-group">

                <label>
                    Business Name *
                </label>

                <input
                    type="text"
                    name="business_name"
                    placeholder="e.g. Mweetwa Electronics"
                    value="<?= htmlspecialchars($_POST["business_name"] ?? "") ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Business Type *
                </label>

                <select
                    name="business_type"
                    required
                >

                    <option value="">
                        Select business type
                    </option>

                    <option value="Retail"
                        <?= (($_POST["business_type"] ?? "") === "Retail")
                            ? "selected"
                            : "" ?>>
                        Retail
                    </option>

                    <option value="Wholesale"
                        <?= (($_POST["business_type"] ?? "") === "Wholesale")
                            ? "selected"
                            : "" ?>>
                        Wholesale
                    </option>

                    <option value="Agriculture"
                        <?= (($_POST["business_type"] ?? "") === "Agriculture")
                            ? "selected"
                            : "" ?>>
                        Agriculture
                    </option>

                    <option value="Restaurant"
                        <?= (($_POST["business_type"] ?? "") === "Restaurant")
                            ? "selected"
                            : "" ?>>
                        Restaurant / Food
                    </option>

                    <option value="Services"
                        <?= (($_POST["business_type"] ?? "") === "Services")
                            ? "selected"
                            : "" ?>>
                        Services
                    </option>

                    <option value="Construction"
                        <?= (($_POST["business_type"] ?? "") === "Construction")
                            ? "selected"
                            : "" ?>>
                        Construction
                    </option>

                    <option value="Technology"
                        <?= (($_POST["business_type"] ?? "") === "Technology")
                            ? "selected"
                            : "" ?>>
                        Technology
                    </option>

                    <option value="Other"
                        <?= (($_POST["business_type"] ?? "") === "Other")
                            ? "selected"
                            : "" ?>>
                        Other
                    </option>

                </select>

            </div>


            <div class="form-group">

                <label>
                    Business Phone *
                </label>

                <input
                    type="tel"
                    name="business_phone"
                    placeholder="+260..."
                    value="<?= htmlspecialchars($_POST["business_phone"] ?? "") ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Business Email
                </label>

                <input
                    type="email"
                    name="business_email"
                    placeholder="business@example.com"
                    value="<?= htmlspecialchars($_POST["business_email"] ?? "") ?>"
                >

            </div>


            <div class="form-group full">

                <label>
                    Business Address
                </label>

                <textarea
                    name="business_address"
                    placeholder="Enter your business address"
                ><?= htmlspecialchars($_POST["business_address"] ?? "") ?></textarea>

            </div>


        </div>


        <!-- ====================================================
             OWNER ACCOUNT
        ===================================================== -->

        <div class="section-title">

            Owner Account

        </div>


        <div class="form-grid">


            <div class="form-group">

                <label>
                    Full Name *
                </label>

                <input
                    type="text"
                    name="full_name"
                    placeholder="Your full name"
                    value="<?= htmlspecialchars($_POST["full_name"] ?? "") ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Username *
                </label>

                <input
                    type="text"
                    name="username"
                    placeholder="Choose a username"
                    value="<?= htmlspecialchars($_POST["username"] ?? "") ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Email Address *
                </label>

                <input
                    type="email"
                    name="email"
                    placeholder="you@example.com"
                    value="<?= htmlspecialchars($_POST["email"] ?? "") ?>"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Phone Number
                </label>

                <input
                    type="tel"
                    name="phone"
                    placeholder="+260..."
                    value="<?= htmlspecialchars($_POST["phone"] ?? "") ?>"
                >

            </div>


            <div class="form-group">

                <label>
                    Password *
                </label>

                <input
                    type="password"
                    name="password"
                    placeholder="Minimum 8 characters"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Confirm Password *
                </label>

                <input
                    type="password"
                    name="confirm_password"
                    placeholder="Repeat your password"
                    required
                >

            </div>


        </div>


        <!-- ====================================================
             SUBMIT
        ===================================================== -->

        <div class="submit-area">

            <div class="login-link">

                Already have an account?

                <a href="login.php">
                    Sign in
                </a>

            </div>


            <button type="submit">

                Create Business Account

            </button>

        </div>


    </form>


</div>


</body>

</html>