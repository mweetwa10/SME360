<?php

// ============================================================
// SME360 - PRODUCT CATEGORIES
// ============================================================

require_once "auth_check.php";
require_once "db.php";


// ============================================================
// GET LOGGED-IN BUSINESS
// ============================================================

$business_id = $_SESSION["business_id"];


// ============================================================
// VARIABLES
// ============================================================

$message = "";

$error = "";


// ============================================================
// GENERATE CATEGORY ID
// ============================================================

function generateCategoryID($pdo)
{

    $prefix = "CAT";

    $query = $pdo->query("
        SELECT category_id

        FROM product_categories

        ORDER BY category_id DESC

        LIMIT 1
    ");

    $lastID = $query->fetchColumn();


    if (!$lastID) {

        return $prefix . "0001";

    }


    $number = (int) substr(

        $lastID,

        strlen($prefix)

    );


    $number++;


    return $prefix
        .
        str_pad(

            $number,

            4,

            "0",

            STR_PAD_LEFT

        );

}


// ============================================================
// ADD CATEGORY
// ============================================================

if (

    $_SERVER["REQUEST_METHOD"] === "POST"

    &&

    isset($_POST["add_category"])

) {


    $category_name = trim(

        $_POST["category_name"] ?? ""

    );


    $description = trim(

        $_POST["description"] ?? ""

    );


    // ========================================================
    // VALIDATION
    // ========================================================

    if ($category_name === "") {


        $error =
            "Please enter a category name.";


    } else {


        // ====================================================
        // CHECK DUPLICATE CATEGORY
        // ====================================================

        $duplicateCheck = $pdo->prepare("

            SELECT category_id

            FROM product_categories

            WHERE business_id = :business_id

            AND LOWER(category_name)
                = LOWER(:category_name)

            LIMIT 1

        ");


        $duplicateCheck->execute([

            ":business_id" =>
                $business_id,

            ":category_name" =>
                $category_name

        ]);


        if ($duplicateCheck->fetch()) {


            $error =
                "A category with this name already exists.";


        } else {


            // =================================================
            // INSERT CATEGORY
            // =================================================

            try {


                $category_id =
                    generateCategoryID($pdo);


                $insert = $pdo->prepare("

                    INSERT INTO product_categories (

                        category_id,

                        business_id,

                        category_name,

                        description,

                        status

                    )

                    VALUES (

                        :category_id,

                        :business_id,

                        :category_name,

                        :description,

                        'active'

                    )

                ");


                $insert->execute([

                    ":category_id" =>
                        $category_id,

                    ":business_id" =>
                        $business_id,

                    ":category_name" =>
                        $category_name,

                    ":description" =>
                        $description !== ""

                        ? $description

                        : null

                ]);


                $message =
                    "Category added successfully.";


            } catch (PDOException $e) {


                $error =
                    "Unable to add category. Please try again.";


            }

        }

    }

}


// ============================================================
// TOGGLE CATEGORY STATUS
// ============================================================

if (

    isset($_GET["toggle"])

    &&

    $_GET["toggle"] !== ""

) {


    $category_id =
        $_GET["toggle"];


    // ========================================================
    // CHECK IF CATEGORY HAS PRODUCTS
    // ========================================================

    $productCheck = $pdo->prepare("

        SELECT COUNT(*)

        FROM products

        WHERE category_id = :category_id

        AND business_id = :business_id

    ");


    $productCheck->execute([

        ":category_id" =>
            $category_id,

        ":business_id" =>
            $business_id

    ]);


    $productCount =
        $productCheck->fetchColumn();


    if ($productCount > 0) {


        // ====================================================
        // CATEGORY WITH PRODUCTS
        // ====================================================

        $error =
            "This category contains products. It can only be deactivated.";


        // ====================================================
        // CHECK CURRENT STATUS
        // ====================================================

        $statusCheck = $pdo->prepare("

            SELECT status

            FROM product_categories

            WHERE category_id = :category_id

            AND business_id = :business_id

            LIMIT 1

        ");


        $statusCheck->execute([

            ":category_id" =>
                $category_id,

            ":business_id" =>
                $business_id

        ]);


        $currentStatus =
            $statusCheck->fetchColumn();


        if ($currentStatus === "active") {


            $toggleQuery = $pdo->prepare("

                UPDATE product_categories

                SET status = 'inactive'

                WHERE category_id = :category_id

                AND business_id = :business_id

            ");


            $toggleQuery->execute([

                ":category_id" =>
                    $category_id,

                ":business_id" =>
                    $business_id

            ]);


            header(
                "Location: product_categories.php"
            );

            exit;

        }


    } else {


        // ====================================================
        // CATEGORY HAS NO PRODUCTS
        // ====================================================

        $toggleQuery = $pdo->prepare("

            UPDATE product_categories

            SET status =

                CASE

                    WHEN status = 'active'

                    THEN 'inactive'

                    ELSE 'active'

                END

            WHERE category_id = :category_id

            AND business_id = :business_id

        ");


        $toggleQuery->execute([

            ":category_id" =>
                $category_id,

            ":business_id" =>
                $business_id

        ]);


        header(
            "Location: product_categories.php"
        );

        exit;

    }

}


// ============================================================
// GET CATEGORIES
// ============================================================

$categoryQuery = $pdo->prepare("

    SELECT

        c.category_id,

        c.category_name,

        c.description,

        c.status,

        c.created_at,

        COUNT(p.product_id)
            AS product_count

    FROM product_categories c

    LEFT JOIN products p

        ON c.category_id =
            p.category_id

        AND c.business_id =
            p.business_id

    WHERE c.business_id =
        :business_id

    GROUP BY

        c.category_id,

        c.category_name,

        c.description,

        c.status,

        c.created_at

    ORDER BY

        c.created_at DESC

");


$categoryQuery->execute([

    ":business_id" =>
        $business_id

]);


$categories =
    $categoryQuery->fetchAll();


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

    Categories | SME360

</title>


<style>

/* ============================================================
   RESET
============================================================ */

* {

    margin: 0;

    padding: 0;

    box-sizing: border-box;

}


/* ============================================================
   BODY
============================================================ */

body {

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background:
        #f4f7fb;

    color:
        #1f2937;

}


/* ============================================================
   SIDEBAR
============================================================ */

.sidebar {

    width:
        250px;

    background:
        #111827;

    color:
        white;

    position:
        fixed;

    top:
        0;

    left:
        0;

    bottom:
        0;

    padding:
        25px 18px;

}


.logo {

    font-size:
        27px;

    font-weight:
        700;

    letter-spacing:
        1px;

    padding:
        0 15px 30px;

}


.logo span {

    color:
        #22c55e;

}


.nav-link {

    display:
        flex;

    align-items:
        center;

    gap:
        12px;

    padding:
        12px 15px;

    color:
        #d1d5db;

    text-decoration:
        none;

    border-radius:
        8px;

    margin-bottom:
        5px;

    font-size:
        14px;

}


.nav-link:hover,
.nav-link.active {

    background:
        #1f2937;

    color:
        #22c55e;

}


.nav-title {

    color:
        #6b7280;

    font-size:
        11px;

    font-weight:
        700;

    text-transform:
        uppercase;

    padding:
        0 15px;

    margin:
        20px 0 10px;

}


.logout {

    margin-top:
        30px;

    color:
        #f87171;

}


/* ============================================================
   MAIN
============================================================ */

.main {

    margin-left:
        250px;

    min-height:
        100vh;

}


/* ============================================================
   TOPBAR
============================================================ */

.topbar {

    height:
        75px;

    background:
        white;

    border-bottom:
        1px solid
        #e5e7eb;

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    padding:
        0 35px;

}


.topbar h1 {

    font-size:
        22px;

}


.user {

    font-size:
        14px;

    color:
        #6b7280;

}


.user strong {

    color:
        #111827;

}


/* ============================================================
   CONTENT
============================================================ */

.content {

    padding:
        30px 35px;

}


/* ============================================================
   PAGE HEADER
============================================================ */

.page-header {

    display:
        flex;

    align-items:
        center;

    justify-content:
        space-between;

    margin-bottom:
        25px;

}


.page-header h2 {

    font-size:
        20px;

}


.page-header p {

    color:
        #6b7280;

    font-size:
        13px;

    margin-top:
        5px;

}


.add-button {

    background:
        #22c55e;

    color:
        white;

    border:
        none;

    padding:
        12px 18px;

    border-radius:
        8px;

    font-weight:
        700;

    cursor:
        pointer;

}


.add-button:hover {

    background:
        #16a34a;

}


/* ============================================================
   MESSAGE
============================================================ */

.message {

    padding:
        13px 15px;

    border-radius:
        8px;

    margin-bottom:
        20px;

    font-size:
        14px;

}


.success {

    background:
        #dcfce7;

    color:
        #166534;

}


.error {

    background:
        #fee2e2;

    color:
        #991b1b;

}


/* ============================================================
   FORM CARD
============================================================ */

.form-card {

    background:
        white;

    padding:
        25px;

    border-radius:
        12px;

    border:
        1px solid
        #e5e7eb;

    margin-bottom:
        25px;

    display:
        none;

}


.form-card.show {

    display:
        block;

}


.form-card h3 {

    font-size:
        17px;

    margin-bottom:
        20px;

}


.form-group {

    display:
        flex;

    flex-direction:
        column;

    margin-bottom:
        20px;

}


label {

    font-size:
        13px;

    font-weight:
        600;

    margin-bottom:
        7px;

}


input,
textarea {

    padding:
        12px;

    border:
        1px solid
        #d1d5db;

    border-radius:
        7px;

    font-size:
        14px;

    outline:
        none;

}


input:focus,
textarea:focus {

    border-color:
        #22c55e;

}


textarea {

    min-height:
        90px;

    resize:
        vertical;

}


.form-actions {

    display:
        flex;

    gap:
        10px;

}


.submit-button {

    background:
        #22c55e;

    color:
        white;

    border:
        none;

    padding:
        12px 20px;

    border-radius:
        7px;

    cursor:
        pointer;

    font-weight:
        600;

}


.cancel-button {

    background:
        #e5e7eb;

    color:
        #374151;

    border:
        none;

    padding:
        12px 20px;

    border-radius:
        7px;

    cursor:
        pointer;

}


/* ============================================================
   TABLE
============================================================ */

.table-card {

    background:
        white;

    border:
        1px solid
        #e5e7eb;

    border-radius:
        12px;

    overflow:
        hidden;

}


.table-wrapper {

    overflow-x:
        auto;

}


table {

    width:
        100%;

    border-collapse:
        collapse;

}


th {

    background:
        #f9fafb;

    text-align:
        left;

    padding:
        14px 18px;

    font-size:
        11px;

    text-transform:
        uppercase;

    color:
        #6b7280;

}


td {

    padding:
        15px 18px;

    border-top:
        1px solid
        #f3f4f6;

    font-size:
        13px;

}


.category-name {

    font-weight:
        600;

    color:
        #111827;

}


.category-id {

    font-size:
        11px;

    color:
        #9ca3af;

    margin-top:
        3px;

}


.status {

    padding:
        5px 9px;

    border-radius:
        15px;

    font-size:
        11px;

    font-weight:
        600;

}


.status-active {

    background:
        #dcfce7;

    color:
        #15803d;

}


.status-inactive {

    background:
        #fee2e2;

    color:
        #b91c1c;

}


.action-link {

    color:
        #2563eb;

    text-decoration:
        none;

    font-size:
        12px;

    font-weight:
        600;

}


.empty {

    text-align:
        center;

    padding:
        50px;

    color:
        #9ca3af;

}


/* ============================================================
   MOBILE
============================================================ */

@media (max-width: 800px) {


    .sidebar {

        width:
            70px;

        padding:
            20px 10px;

    }


    .logo {

        font-size:
            0;

        text-align:
            center;

    }


    .logo::before {

        content:
            "S";

        font-size:
            25px;

        color:
            #22c55e;

    }


    .nav-link {

        justify-content:
            center;

    }


    .nav-link span:last-child,
    .nav-title {

        display:
            none;

    }


    .main {

        margin-left:
            70px;

    }


    .content {

        padding:
            20px;

    }


    .page-header {

        align-items:
            flex-start;

        gap:
            15px;

        flex-direction:
            column;

    }

}

</style>


<script>

function toggleForm() {

    const form =
        document.getElementById(
            "addCategoryForm"
        );

    form.classList.toggle("show");

}

</script>


</head>


<body>


<!-- ============================================================
     SIDEBAR
============================================================ -->

<aside class="sidebar">


    <div class="logo">

        SME<span>360</span>

    </div>


    <div class="nav-title">

        Main Menu

    </div>


    <a
        href="dashboard.php"
        class="nav-link"
    >

        <span>▦</span>

        <span>Dashboard</span>

    </a>


    <a
        href="products.php"
        class="nav-link"
    >

        <span>□</span>

        <span>Products</span>

    </a>


    <a
        href="product_categories.php"
        class="nav-link active"
    >

        <span>▤</span>

        <span>Categories</span>

    </a>


    <a
        href="inventory.php"
        class="nav-link"
    >

        <span>◫</span>

        <span>Inventory</span>

    </a>


    <a
        href="sales.php"
        class="nav-link"
    >

        <span>$</span>

        <span>Sales</span>

    </a>


    <a
        href="customers.php"
        class="nav-link"
    >

        <span>♙</span>

        <span>Customers</span>

    </a>


    <div class="nav-title">

        Finance

    </div>


    <a
        href="expenses.php"
        class="nav-link"
    >

        <span>↓</span>

        <span>Expenses</span>

    </a>


    <a
        href="invoices.php"
        class="nav-link"
    >

        <span>▤</span>

        <span>Invoices</span>

    </a>


    <a
        href="payments.php"
        class="nav-link"
    >

        <span>✓</span>

        <span>Payments</span>

    </a>


    <div class="nav-title">

        Management

    </div>


    <a
        href="reports.php"
        class="nav-link"
    >

        <span>◴</span>

        <span>Reports</span>

    </a>


    <a
        href="users.php"
        class="nav-link"
    >

        <span>♙</span>

        <span>Users</span>

    </a>


    <a
        href="settings.php"
        class="nav-link"
    >

        <span>⚙</span>

        <span>Settings</span>

    </a>


    <a
        href="logout.php"
        class="nav-link logout"
    >

        <span>↪</span>

        <span>Logout</span>

    </a>


</aside>


<!-- ============================================================
     MAIN
============================================================ -->

<main class="main">


    <!-- ========================================================
         TOPBAR
    ========================================================= -->

    <header class="topbar">


        <div>

            <h1>

                Product Categories

            </h1>

        </div>


        <div class="user">

            Welcome,

            <strong>

                <?= htmlspecialchars(

                    $_SESSION["full_name"]

                ) ?>

            </strong>

        </div>


    </header>


    <!-- ========================================================
         CONTENT
    ========================================================= -->

    <div class="content">


        <!-- ====================================================
             PAGE HEADER
        ===================================================== -->

        <div class="page-header">


            <div>

                <h2>

                    Categories

                </h2>


                <p>

                    Organize your products and services
                    into categories.

                </p>

            </div>


            <button

                class="add-button"

                onclick="toggleForm()"

            >

                + Add Category

            </button>


        </div>


        <!-- ====================================================
             MESSAGES
        ===================================================== -->

        <?php if (
            $message !== ""
        ): ?>


            <div class="message success">

                <?= htmlspecialchars(

                    $message

                ) ?>

            </div>


        <?php endif; ?>


        <?php if (
            $error !== ""
        ): ?>


            <div class="message error">

                <?= htmlspecialchars(

                    $error

                ) ?>

            </div>


        <?php endif; ?>


        <!-- ====================================================
             ADD CATEGORY FORM
        ===================================================== -->

        <div

            class="form-card"

            id="addCategoryForm"

        >


            <h3>

                Add New Category

            </h3>


            <form

                method="POST"

                action=""

            >


                <div class="form-group">


                    <label>

                        Category Name *

                    </label>


                    <input

                        type="text"

                        name="category_name"

                        placeholder="
                            e.g. Electronics,
                            Groceries, Clothing
                        "

                        required

                    >


                </div>


                <div class="form-group">


                    <label>

                        Description

                    </label>


                    <textarea

                        name="description"

                        placeholder="
                            Optional description
                            for this category
                        "

                    ></textarea>


                </div>


                <div class="form-actions">


                    <button

                        type="submit"

                        name="add_category"

                        class="submit-button"

                    >

                        Save Category

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


        <!-- ====================================================
             CATEGORY TABLE
        ===================================================== -->

        <div class="table-card">


            <div class="table-wrapper">


                <table>


                    <thead>


                        <tr>


                            <th>

                                Category

                            </th>


                            <th>

                                Description

                            </th>


                            <th>

                                Products

                            </th>


                            <th>

                                Status

                            </th>


                            <th>

                                Action

                            </th>


                        </tr>


                    </thead>


                    <tbody>


                    <?php if (
                        empty($categories)
                    ): ?>


                        <tr>


                            <td

                                colspan="5"

                                class="empty"

                            >

                                No categories have
                                been created yet.

                            </td>


                        </tr>


                    <?php else: ?>


                        <?php foreach (

                            $categories

                            as $category

                        ): ?>


                            <tr>


                                <td>


                                    <div

                                        class="
                                            category-name
                                        "

                                    >

                                        <?= htmlspecialchars(

                                            $category[
                                                "category_name"
                                            ]

                                        ) ?>


                                    </div>


                                    <div

                                        class="
                                            category-id
                                        "

                                    >

                                        <?= htmlspecialchars(

                                            $category[
                                                "category_id"
                                            ]

                                        ) ?>


                                    </div>


                                </td>


                                <td>


                                    <?= htmlspecialchars(

                                        $category[
                                            "description"
                                        ]

                                        ??

                                        "No description"

                                    ) ?>


                                </td>


                                <td>


                                    <?= (int)

                                        $category[
                                            "product_count"
                                        ]

                                    ?>


                                </td>


                                <td>


                                    <span

                                        class="
                                            status
                                            status-
                                            <?= $category[
                                                "status"
                                            ] ?>
                                        "

                                    >


                                        <?= ucfirst(

                                            $category[
                                                "status"
                                            ]

                                        ) ?>


                                    </span>


                                </td>


                                <td>


                                    <a

                                        href="
                                            product_categories.php
                                            ?toggle=
                                            <?= urlencode(

                                                $category[
                                                    "category_id"
                                                ]

                                            ) ?>
                                        "

                                        class="
                                            action-link
                                        "

                                        onclick="
                                            return confirm(
                                                'Are you sure you want to change the status of this category?'
                                            );
                                        "

                                    >


                                        <?= $category[
                                            "status"
                                        ]

                                        === "active"

                                        ? "Deactivate"

                                        : "Activate"

                                        ?>


                                    </a>


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

