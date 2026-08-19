<?php
ob_start();
session_start();
include 'db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = strtoupper(trim($_POST['username']));
    $password = trim($_POST['password']);


    $prefix = substr($username, 0, 3);

    switch ($prefix) {

        case 'DOC':
            $role = 'Doctor';
            $table = 'doctors';
            $idColumn = 'doctor_id';
            break;

        case 'NUR':
            $role = 'Nurse';
            $table = 'nurses';
            $idColumn = 'nurse_id';
            break;

        case 'REC':
            $role = 'Receptionist';
            $table = 'receptionists';
            $idColumn = 'receptionist_id';
            break;

        case 'ADM':
            $role = 'admin';
            $table = 'admins';
            $idColumn = 'admin_id';
            break;

        case 'PHA':
            $role = 'Pharmacist';
            $table = 'pharmacists';
            $idColumn = 'pharmacist_id';
            break;

        case 'LAB':
            $role = 'Lab Staff';
            $table = 'lab_staff';
            $idColumn = 'lab_staff_id';
            break;

        default:
            $error = "Invalid Staff ID.";
            $table = '';
            break;
    }

    if (!empty($table)) {

        $stmt = $conn->prepare("
            SELECT *
            FROM $table
            WHERE $idColumn = ?
            LIMIT 1
        ");

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {

            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                $_SESSION['user_id'] = $user[$idColumn];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $role;
                $_SESSION['last_activity'] = time();

                switch ($role) {

                    case 'Doctor':
                        header("Location: doctor-dash.php");
                        exit;

                    case 'Nurse':
                        header("Location: nurse-dash.php");
                        exit;

                    case 'Receptionist':
                        header("Location: reception-dash.php");
                        exit;

                    case 'admin':
                        header("Location: admin-dash.php");
                        exit;

                    case 'Pharmacist':
                        header("Location: pharmacy-dash.php");
                        exit;

                    case 'Lab Staff':
                        header("Location: lab-dash.php");
                        exit;
                }

            } else {
                $error = "Incorrect password.";
            }

        } else {
            $error = "Staff ID not found.";
        }
    }
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MediPulse | Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;
}

body{
    min-height:100vh;
    background:url('background.jpg') no-repeat center center fixed;
    background-size:cover;
    display:flex;
    justify-content:center;
    align-items:center;
}

.login-container{
    width:100%;
    max-width:420px;
    background:#fff;
    padding:40px;
    border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}

.logo{
    text-align:center;
    margin-bottom:10px;
}

.logo img{
    width:300px;
}

h2{
    text-align:center;
    margin-bottom:25px;
    color:#333;
}

.form-group{
    margin-bottom:18px;
}

.form-group label{
    display:block;
    margin-bottom:6px;
    font-size:14px;
    color:#333;
}

.form-group input{
    width:100%;
    padding:12px;
    border:1px solid #ccc;
    border-radius:6px;
    font-size:14px;
}

.form-group input:focus{
    outline:none;
    border-color:#0f4c81;
}

.login-btn{
    width:100%;
    padding:12px;
    background:#0f4c81;
    color:#fff;
    border:none;
    border-radius:6px;
    font-size:15px;
    font-weight:600;
    cursor:pointer;
    transition:.3s;
}

.login-btn:hover{
    background:#0c3c66;
}

.error-msg{
    color:red;
    text-align:center;
    margin-bottom:15px;
    font-size:14px;
}

.footer-text{
    text-align:center;
    margin-top:18px;
    font-size:12px;
    color:#777;
}

.footer-text span{
    color:#0f4c81;
    font-weight:600;
}

.staff-hint{
    text-align:center;
    margin-bottom:15px;
    color:#666;
    font-size:12px;
}

</style>
</head>
<body>

<div class="login-container">

    <div class="logo">
        <img src="logo1.png" alt="MediPulse Logo">
    </div>

    <h2>Welcome Back</h2>

    <?php if(!empty($error)): ?>
        <div class="error-msg">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <div class="form-group">
            <label>Staff ID</label>
            <input
                type="text"
                name="username"
                placeholder="Enter Staff ID"
                required
            >
        </div>

        <div class="form-group">
            <label>Password</label>
            <input
                type="password"
                name="password"
                placeholder="Enter Password"
                required
            >
        </div>

        <button type="submit" class="login-btn">
            Login
        </button>

    </form>

    <div class="footer-text">
        © 2026 <span>MediPulse</span>
    </div>

</div>

</body>
</html>
```
