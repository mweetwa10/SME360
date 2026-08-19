<?php
session_start();
include 'db.php';

$message = "";

if(isset($_POST['create_admin'])){

    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $gender = $_POST['gender'];
    $dob = $_POST['dob'];
    $nrc = $_POST['nrc'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $nok_full_name = $_POST['nok_full_name'];
    $nok_phone = $_POST['nok_phone'];

    
    $check = $conn->prepare("SELECT admin_id FROM admins WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if($check->num_rows > 0){
        $message = "Email already exists!";
    } else {

        
        $prefix = "ADM";

        $result = $conn->query("SELECT admin_id FROM admins ORDER BY admin_id DESC LIMIT 1");
        $row = $result->fetch_assoc();

        if($row){
            $lastNumber = (int) str_replace($prefix, "", $row['admin_id']);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        $admin_id = $prefix . str_pad($newNumber, 4, "0", STR_PAD_LEFT);

        
        $stmt = $conn->prepare("
            INSERT INTO admins 
            (admin_id, full_name, email, phone, gender, dob, nrc, password, nok_full_name, nok_phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssssssssss",
            $admin_id,
            $full_name,
            $email,
            $phone,
            $gender,
            $dob,
            $nrc,
            $password,
            $nok_full_name,
            $nok_phone
        );

        if($stmt->execute()){
            $message = "Admin created successfully! ID: $admin_id";
        } else {
            $message = "Error creating admin.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Create Admin</title>

<style>
body{
    font-family:Segoe UI;
    background:#f4f7fb;
}

.container{
    width:500px;
    margin:40px auto;
    background:#fff;
    padding:20px;
    border-radius:10px;
    box-shadow:0 5px 15px rgba(0,0,0,0.1);
}

h2{
    text-align:center;
    color:#0f4c81;
}

input, select{
    width:100%;
    padding:10px;
    margin-top:10px;
    border:1px solid #ccc;
    border-radius:5px;
}

button{
    width:100%;
    padding:10px;
    margin-top:15px;
    background:#0f4c81;
    color:#fff;
    border:none;
    border-radius:5px;
    cursor:pointer;
}

button:hover{
    background:#0c3c66;
}

.msg{
    margin-top:10px;
    text-align:center;
    font-weight:bold;
    color:green;
}
</style>

</head>

<body>

<div class="container">

<h2>Create Admin Account</h2>

<form method="POST">

<input type="text" name="full_name" placeholder="Full Name" required>

<input type="email" name="email" placeholder="Email" required>

<input type="text" name="phone" placeholder="Phone" required>

<select name="gender" required>
    <option value="">Select Gender</option>
    <option value="Male">Male</option>
    <option value="Female">Female</option>
</select>

<input type="date" name="dob" required>

<input type="text" name="nrc" placeholder="NRC" required>

<input type="password" name="password" placeholder="Password" required>

<input type="text" name="nok_full_name" placeholder="Next of Kin Full Name" required>

<input type="text" name="nok_phone" placeholder="Next of Kin Phone" required>

<button type="submit" name="create_admin">Create Admin</button>

</form>

<?php if($message): ?>
<div class="msg"><?= $message ?></div>
<?php endif; ?>

</div>

</body>
</html>