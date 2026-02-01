<?php
session_start();
if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'admin'){ header("Location: ../login.php"); exit; }

include '../config/db.php';

$user_id = (int)$_SESSION['user_id'];
$success = $error = "";

/* =========================
UPDATE PROFILE
========================= */
if(isset($_POST['update_profile'])){
$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');

if($first === '' || $last === ''){
$error = "First name and last name are required.";
} else {
$stmt = $conn->prepare("
UPDATE users
SET first_name=?, last_name=?, phone=?
WHERE user_id=?
");
$stmt->bind_param("sssi", $first, $last, $phone, $user_id);
$stmt->execute();
$stmt->close();

$_SESSION['username'] = $_SESSION['username']; // keep session
$success = "Profile updated successfully.";
}
}

/* =========================
FETCH USER INFO
========================= */
$stmt = $conn->prepare("
SELECT username, first_name, last_name, phone, role, created_at
FROM users
WHERE user_id=?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Profile</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body{ background:#f4f6f9; }
.main-content{ padding-top:80px; }
.modern-card{ border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); }
</style>
</head>
<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<!-- TOP NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top" style="margin-left: 260px; width: calc(100% - 260px);">
<div class="container-fluid">
<span class="navbar-brand fw-bold ms-2">DO HIVES GENERAL MERCHANDISE</span>
<div class="ms-auto">
<a href="../logout.php" class="btn btn-outline-danger btn-sm">
<i class="fa-solid fa-right-from-bracket me-1"></i> Logout
</a>
</div>
</div>
</nav>

<main class="container main-content" style="margin-left: 260px;">
<div class="row justify-content-center">
<div class="col-lg-6">

<div class="card modern-card">
<div class="card-body">

<h4 class="fw-bold mb-3">
<i class="fa-solid fa-user-gear me-2"></i>Admin Profile
</h4>

<?php if($success): ?>
<div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php if($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<form method="POST">

<div class="mb-3">
<label class="form-label">Username</label>
<input class="form-control" value="<?= h($user['username']) ?>" disabled>
</div>

<div class="row g-3">
<div class="col-md-6">
<label class="form-label">First Name</label>
<input class="form-control" name="first_name" value="<?= h($user['first_name']) ?>" required>
</div>

<div class="col-md-6">
<label class="form-label">Last Name</label>
<input class="form-control" name="last_name" value="<?= h($user['last_name']) ?>" required>
</div>
</div>

<div class="mb-3 mt-3">
<label class="form-label">Phone</label>
<input class="form-control" name="phone" value="<?= h($user['phone']) ?>">
</div>

<div class="row g-3 mt-1">
<div class="col-md-6">
<label class="form-label">Role</label>
<input class="form-control" value="<?= strtoupper($user['role']) ?>" disabled>
</div>

<div class="col-md-6">
<label class="form-label">Account Created</label>
<input class="form-control" value="<?= date("M d, Y", strtotime($user['created_at'])) ?>" disabled>
</div>
</div>

<button class="btn btn-dark w-100 mt-4" name="update_profile">
<i class="fa-solid fa-save me-1"></i> Save Changes
</button>

</form>

</div>
</div>

</div>
</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>