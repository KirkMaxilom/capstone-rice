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

$username = $_SESSION['username'] ?? 'Cashier';
$user_id = (int)($_SESSION['user_id'] ?? 0);

include '../config/db.php';
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/*
CASHIER CUSTOMERS
Table: customers(customer_id, first_name, last_name, phone, address, created_at)
- Cashier can: add, edit, view
- Used for Sales (POS) and Receivables
*/

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

/* =========================
Add Customer
========================= */
if(isset($_POST['add_customer'])){
$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$addr = trim($_POST['address'] ?? '');

if($first==='' || $last==='' || $phone===''){
header("Location: customers.php?error=" . urlencode("First name, last name, and phone are required."));
exit;
}

$stmt = $conn->prepare("INSERT INTO customers (first_name, last_name, phone, address, created_at) VALUES (?,?,?,?,NOW())");
if(!$stmt){ die("SQL PREPARE ERROR: ".$conn->error); }
$stmt->bind_param("ssss", $first, $last, $phone, $addr);
$stmt->execute();
$stmt->close();

header("Location: customers.php?success=" . urlencode("Customer added successfully."));
exit;
}

/* =========================
Edit Customer
========================= */
if(isset($_POST['edit_customer'])){
$cid = (int)($_POST['customer_id'] ?? 0);
$first = trim($_POST['first_name'] ?? '');
$last = trim($_POST['last_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$addr = trim($_POST['address'] ?? '');

if($cid<=0 || $first==='' || $last==='' || $phone===''){
header("Location: customers.php?error=" . urlencode("Invalid customer data."));
exit;
}

$stmt = $conn->prepare("UPDATE customers SET first_name=?, last_name=?, phone=?, address=? WHERE customer_id=?");
if(!$stmt){ die("SQL PREPARE ERROR: ".$conn->error); }
$stmt->bind_param("ssssi", $first, $last, $phone, $addr, $cid);
$stmt->execute();
$stmt->close();

header("Location: customers.php?success=" . urlencode("Customer updated."));
exit;
}

/* =========================
Search + List
========================= */
$q = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM customers";
$params = [];
$types = "";

if($q !== ''){
$sql .= " WHERE first_name LIKE CONCAT('%',?,'%')
OR last_name LIKE CONCAT('%',?,'%')
OR phone LIKE CONCAT('%',?,'%')
OR address LIKE CONCAT('%',?,'%')";
$params = [$q,$q,$q,$q];
$types = "ssss";
}
$sql .= " ORDER BY created_at DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if(!$stmt){ die("SQL PREPARE ERROR: ".$conn->error."<br><pre>$sql</pre>"); }
if($types !== ''){
$stmt->bind_param($types, ...$params);
}
$stmt->execute();
$customers = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Customers | Cashier</title>

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
.modern-card{ border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); }
.table td,.table th{ vertical-align:middle; }
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

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
<div>
<h3 class="fw-bold mb-1">Customers</h3>
<div class="text-muted">Add and manage customer records (for sales & utang).</div>
</div>
<button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
<i class="fa-solid fa-user-plus me-1"></i> Add Customer
</button>
</div>

<?php if($success): ?><div class="alert alert-success py-2"><?= h($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>

<!-- SEARCH -->
<div class="card modern-card mb-3">
<div class="card-body">
<form class="row g-2 align-items-end" method="GET">
<div class="col-12 col-md-9">
<label class="form-label">Search</label>
<input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Name, phone, address...">
</div>
<div class="col-12 col-md-3 d-grid">
<button class="btn btn-outline-dark"><i class="fa-solid fa-magnifying-glass me-1"></i> Search</button>
</div>
</form>
</div>
</div>

<!-- TABLE -->
<div class="card modern-card">
<div class="card-body table-responsive">
<table class="table table-striped align-middle mb-0">
<thead class="table-dark">
<tr>
<th>ID</th>
<th>Name</th>
<th>Phone</th>
<th>Address</th>
<th>Created</th>
<th class="text-end">Action</th>
</tr>
</thead>
<tbody>
<?php if($customers && $customers->num_rows>0): ?>
<?php while($c = $customers->fetch_assoc()): ?>
<?php
$name = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''));
?>
<tr>
<td class="fw-bold"><?= (int)$c['customer_id'] ?></td>
<td><?= h($name ?: 'N/A') ?></td>
<td><?= h($c['phone'] ?? '') ?></td>
<td style="max-width:360px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
<?= h($c['address'] ?? '') ?>
</td>
<td><?= $c['created_at'] ? date("M d, Y", strtotime($c['created_at'])) : '-' ?></td>
<td class="text-end">
<button class="btn btn-sm btn-outline-dark"
data-bs-toggle="modal"
data-bs-target="#editCustomerModal<?= (int)$c['customer_id'] ?>">
<i class="fa-solid fa-pen-to-square"></i>
</button>
</td>
</tr>

<!-- EDIT MODAL -->
<div class="modal fade" id="editCustomerModal<?= (int)$c['customer_id'] ?>" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">Edit Customer</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<form method="POST">
<div class="modal-body">
<input type="hidden" name="edit_customer" value="1">
<input type="hidden" name="customer_id" value="<?= (int)$c['customer_id'] ?>">

<div class="mb-2">
<label class="form-label">First Name</label>
<input class="form-control" name="first_name" required value="<?= h($c['first_name'] ?? '') ?>">
</div>
<div class="mb-2">
<label class="form-label">Last Name</label>
<input class="form-control" name="last_name" required value="<?= h($c['last_name'] ?? '') ?>">
</div>
<div class="mb-2">
<label class="form-label">Phone</label>
<input class="form-control" name="phone" required value="<?= h($c['phone'] ?? '') ?>">
</div>
<div class="mb-2">
<label class="form-label">Address</label>
<textarea class="form-control" name="address" rows="2"><?= h($c['address'] ?? '') ?></textarea>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button class="btn btn-dark"><i class="fa-solid fa-floppy-disk me-1"></i> Save</button>
</div>
</form>
</div>
</div>
</div>

<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="6" class="text-center text-muted py-4">No customers found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

</main>

<!-- ADD MODAL -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">Add Customer</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<form method="POST">
<div class="modal-body">
<input type="hidden" name="add_customer" value="1">

<div class="mb-2">
<label class="form-label">First Name</label>
<input class="form-control" name="first_name" required>
</div>
<div class="mb-2">
<label class="form-label">Last Name</label>
<input class="form-control" name="last_name" required>
</div>
<div class="mb-2">
<label class="form-label">Phone</label>
<input class="form-control" name="phone" required>
</div>
<div class="mb-2">
<label class="form-label">Address</label>
<textarea class="form-control" name="address" rows="2"></textarea>
</div>

</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button class="btn btn-dark"><i class="fa-solid fa-user-plus me-1"></i> Add</button>
</div>
</form>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>