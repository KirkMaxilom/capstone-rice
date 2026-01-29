<?php
session_start();
if(!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'cashier'){ header("Location: ../login.php"); exit; }

include '../config/db.php';

$username = $_SESSION['username'] ?? 'Cashier';
$user_id = (int)($_SESSION['user_id'] ?? 0);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Today range (safe vs timezone issues)
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 00:00:00', strtotime('+1 day'));

/* -------------------------
Sales Today (count + revenue)
------------------------- */
$stmt = $conn->prepare("
SELECT
COUNT(*) AS total_sales,
IFNULL(SUM(total_amount),0) AS revenue
FROM sales
WHERE user_id = ?
AND sale_date >= ? AND sale_date < ?
AND (status IS NULL OR LOWER(status) <> 'cancelled')
");
$stmt->bind_param("iss", $user_id, $todayStart, $todayEnd);
$stmt->execute();
$today = $stmt->get_result()->fetch_assoc() ?: ['total_sales'=>0,'revenue'=>0];
$stmt->close();

/* -------------------------
KG Sold Today (from sales_items)
------------------------- */
$stmt = $conn->prepare("
SELECT IFNULL(SUM(si.qty_kg),0) AS sold_kg
FROM sales_items si
JOIN sales s ON si.sale_id = s.sale_id
WHERE s.user_id = ?
AND s.sale_date >= ? AND s.sale_date < ?
AND (s.status IS NULL OR LOWER(s.status) <> 'cancelled')
");
$stmt->bind_param("iss", $user_id, $todayStart, $todayEnd);
$stmt->execute();
$kgToday = $stmt->get_result()->fetch_assoc() ?: ['sold_kg'=>0];
$stmt->close();

/* -------------------------
Pending Utang (AR) for THIS cashier only
Link: account_receivable -> sales -> cashier (user_id)
------------------------- */
$stmt = $conn->prepare("
SELECT IFNULL(SUM(ar.balance),0) AS ar_balance
FROM account_receivable ar
JOIN sales s ON ar.sales_id = s.sale_id
WHERE ar.balance > 0
AND s.user_id = ?
AND (s.status IS NULL OR LOWER(s.status) <> 'cancelled')
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$ar = $stmt->get_result()->fetch_assoc() ?: ['ar_balance'=>0];
$stmt->close();

/* -------------------------
Low stock preview (computed stock <= 50kg)
------------------------- */
$threshold = 50;

$stmt = $conn->prepare("
SELECT p.product_id, p.variety, p.grade,
IFNULL(SUM(CASE
WHEN LOWER(it.type)='in' THEN it.qty_kg
WHEN LOWER(it.type)='out' THEN -it.qty_kg
WHEN LOWER(it.type)='adjust' THEN it.qty_kg
ELSE 0
END),0) AS stock_kg
FROM products p
LEFT JOIN inventory_transactions it ON it.product_id = p.product_id
WHERE p.archived=0
GROUP BY p.product_id
HAVING stock_kg <= ?
ORDER BY stock_kg ASC
LIMIT 5
");
$stmt->bind_param("d", $threshold);
$stmt->execute();
$lowStock = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cashier Dashboard | DOHIVES</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }

/* Cards */
.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); transition:.3s; }
.modern-card:hover { transform:translateY(-4px); }

/* Gradients */
.bg-gradient-primary {background:linear-gradient(135deg,#1d2671,#c33764);}
.bg-gradient-success {background:linear-gradient(135deg,#11998e,#38ef7d);}
.bg-gradient-info {background:linear-gradient(135deg,#36d1dc,#5b86e5);}
.bg-gradient-warning {background:linear-gradient(135deg,#f7971e,#ffd200);}

.main-content {
    padding-top: 70px;
    padding-left: 20px;
    padding-right: 20px;
}
</style>
</head>
<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top" style="margin-left: 240px; width: calc(100% - 240px); z-index: 1020;">
<div class="container-fluid">
<span class="navbar-brand fw-bold ms-2">DE ORO HIYS GENERAL MERCHANDISE</span>

<div class="ms-auto dropdown">
<a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
<?= h($username) ?> <small class="text-muted">(Cashier)</small>
</a>
<ul class="dropdown-menu dropdown-menu-end">
<li><a class="dropdown-item" href="cashier_profile.php"><i class="fa-solid fa-user me-2"></i>Profile</a></li>
<li><a class="dropdown-item text-danger" href="../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
</ul>
</div>
</div>
</nav>

<main class="main-content">
<h3 class="fw-bold mb-3">Cashier Dashboard</h3>

<div class="row g-4">
<div class="col-12 col-md-6 col-xl-3">
<div class="card modern-card bg-gradient-primary text-white p-4">
<div class="d-flex justify-content-between">
<div>
<div class="fw-light">Sales Today</div>
<div class="display-6 fw-bold"><?= (int)$today['total_sales'] ?></div>
</div>
<i class="fas fa-receipt fa-3x opacity-75"></i>
</div>
</div>
</div>

<div class="col-12 col-md-6 col-xl-3">
<div class="card modern-card bg-gradient-success text-white p-4">
<div class="d-flex justify-content-between">
<div>
<div class="fw-light">Revenue Today</div>
<div class="h3 fw-bold mb-0">₱<?= number_format((float)$today['revenue'],2) ?></div>
</div>
<i class="fas fa-coins fa-3x opacity-75"></i>
</div>
</div>
</div>

<div class="col-12 col-md-6 col-xl-3">
<div class="card modern-card bg-gradient-info text-white p-4">
<div class="d-flex justify-content-between">
<div>
<div class="fw-light">KG Sold Today</div>
<div class="h3 fw-bold mb-0"><?= number_format((float)$kgToday['sold_kg'],2) ?> kg</div>
</div>
<i class="fas fa-weight-scale fa-3x opacity-75"></i>
</div>
</div>
</div>

<div class="col-12 col-md-6 col-xl-3">
<div class="card modern-card bg-gradient-warning text-dark p-4">
<div class="d-flex justify-content-between">
<div>
<div class="fw-light">Pending Utang (AR)</div>
<div class="h3 fw-bold mb-0">₱<?= number_format((float)$ar['ar_balance'],2) ?></div>
</div>
<i class="fas fa-hand-holding-dollar fa-3x opacity-75"></i>
</div>
</div>
</div>
</div>

<div class="card modern-card mt-4">
<div class="card-body">
<h5 class="fw-bold mb-3">Low Stock (Preview) ≤ <?= number_format($threshold,0) ?> kg</h5>
<div class="table-responsive">
<table class="table table-striped align-middle mb-0">
<thead class="table-dark">
<tr><th>Product</th><th>Stock (kg)</th></tr>
</thead>
<tbody>
<?php if($lowStock && $lowStock->num_rows): ?>
<?php while($p = $lowStock->fetch_assoc()): ?>
<tr>
<td><?= h($p['variety']." - ".$p['grade']) ?></td>
<td class="fw-bold"><?= number_format((float)$p['stock_kg'],2) ?></td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="2" class="text-center text-muted">No low stock items.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>