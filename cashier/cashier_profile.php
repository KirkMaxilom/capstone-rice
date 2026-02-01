<?php
session_start();
if(!isset($_SESSION['user_id'])){
header("Location: ../login.php");
exit;
}
if(strtolower($_SESSION['role'] ?? '') !== 'cashier'){
header("Location: ../login.php");
exit;
}

include "../config/db.php";

$user_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Cashier';

function h($v){
return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

/* =====================
Fetch cashier data
===================== */
$stmt = $conn->prepare("
SELECT user_id, username, first_name, last_name, phone, password, role, status
FROM users
WHERE user_id = ?
LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$user){
header("Location: ../logout.php");
exit;
}

/* =====================
Update profile info
===================== */
if(isset($_POST['update_profile'])){
$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');

if($first === '' || $last === ''){
header("Location: cashier_profile.php?error=" . urlencode("First and last name are required."));
exit;
}

$up = $conn->prepare("
UPDATE users
SET first_name = ?, last_name = ?, phone = ?
WHERE user_id = ?
");
$up->bind_param("sssi", $first, $last, $phone, $user_id);
$up->execute();
$up->close();

header("Location: cashier_profile.php?success=" . urlencode("Profile updated successfully."));
exit;
}

/* =====================
Change password
===================== */
if(isset($_POST['change_password'])){
$old = $_POST['old_password'] ?? '';
$new = $_POST['new_password'] ?? '';
$con = $_POST['confirm_password'] ?? '';

if($old === '' || $new === '' || $con === ''){
header("Location: cashier_profile.php?error=" . urlencode("All password fields are required."));
exit;
}

if($new !== $con){
header("Location: cashier_profile.php?error=" . urlencode("Passwords do not match."));
exit;
}

if(strlen($new) < 6){
header("Location: cashier_profile.php?error=" . urlencode("Password must be at least 6 characters."));
exit;
}

if(!password_verify($old, $user['password'])){
header("Location: cashier_profile.php?error=" . urlencode("Old password is incorrect."));
exit;
}

$hashed = password_hash($new, PASSWORD_DEFAULT);

$up = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
$up->bind_param("si", $hashed, $user_id);
$up->execute();
$up->close();

header("Location: cashier_profile.php?success=" . urlencode("Password changed successfully."));
exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cashier Profile</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }
.main-content {
    padding-top: 70px;
    padding-left: 20px;
    padding-right: 20px;
}
.card{ border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); }
</style>
</head>

<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top" style="margin-left: 220px; width: calc(100% - 220px); z-index: 1020;">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold ms-2">DE ORO HIYS GENERAL MERCHANDISE</span>
        <div class="ms-auto dropdown">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                <?= h($username) ?> <small class="text-muted">(Cashier)</small>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="cashier_profile.php"><i class="fa-solid fa-user me-2"></i>Profile</a></li>
                <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- MAIN CONTENT -->
<main class="main-content">

<div class="container">
<div class="row g-4">

<?php if($success): ?>
<div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="col-md-6">
<div class="card">
<div class="card-body">
<h5 class="fw-bold mb-3">Profile Information</h5>

<form method="post">
<input type="hidden" name="update_profile" value="1">

<div class="mb-2">
<label class="form-label">Username</label>
<input class="form-control" value="<?= h($user['username']) ?>" disabled>
</div>

<div class="mb-2">
<label class="form-label">First Name</label>
<input class="form-control" name="first_name" value="<?= h($user['first_name']) ?>" required>
</div>

<div class="mb-2">
<label class="form-label">Last Name</label>
<input class="form-control" name="last_name" value="<?= h($user['last_name']) ?>" required>
</div>

<div class="mb-3">
<label class="form-label">Phone</label>
<input class="form-control" name="phone" value="<?= h($user['phone']) ?>">
</div>

<button class="btn btn-dark w-100">Save Profile</button>
</form>
</div>
</div>
</div>

<div class="col-md-6">
<div class="card">
<div class="card-body">
<h5 class="fw-bold mb-3">Change Password</h5>

<form method="post">
<input type="hidden" name="change_password" value="1">

<div class="mb-2">
<label class="form-label">Old Password</label>
<input type="password" class="form-control" name="old_password" required>
</div>

<div class="mb-2">
<label class="form-label">New Password</label>
<input type="password" class="form-control" name="new_password" requir  ed>
</div>

<div class="mb-3">
<label class="form-label">Confirm New Password</label>
<input type="password" class="form-control" name="confirm_password" required>
</div>

<button class="btn btn-outline-dark w-100">Update Password</button>
</form>

</div>
</div>
</div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>