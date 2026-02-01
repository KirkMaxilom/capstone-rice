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

/* =========================
Filters (optional)
========================= */
$q = trim($_GET['q'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status = strtolower(trim($_GET['status'] ?? 'all')); // all|paid|unpaid|cancelled

// Build WHERE safely
$where = "WHERE s.user_id = ?";
$params = [$user_id];
$types = "i";

if($q !== ''){
// search by sale_id OR customer name
$where .= " AND (s.sale_id LIKE CONCAT('%',?,'%') OR c.first_name LIKE CONCAT('%',?,'%') OR c.last_name LIKE CONCAT('%',?,'%'))";
$params[] = $q; $params[] = $q; $params[] = $q;
$types .= "sss";
}

if($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)){
$where .= " AND DATE(s.sale_date) >= ?";
$params[] = $date_from;
$types .= "s";
}
if($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)){
$where .= " AND DATE(s.sale_date) <= ?";
$params[] = $date_to;
$types .= "s";
}

if(in_array($status, ['paid','unpaid','cancelled'], true)){
$where .= " AND LOWER(IFNULL(s.status,'')) = ?";
$params[] = $status;
$types .= "s";
} else {
// hide cancelled by default? keep as "all" view. we'll keep all.
}

/* =========================
Pagination
========================= */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

/* =========================
Count total
========================= */
$countSql = "
SELECT COUNT(*) AS cnt
FROM sales s
LEFT JOIN customers c ON s.customer_id = c.customer_id
{$where}
";
$stmt = $conn->prepare($countSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$totalRows = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* =========================
Fetch sales list
========================= */
$listSql = "
SELECT
s.sale_id, s.sale_date, s.total_amount, s.status, s.customer_id,
c.first_name, c.last_name,
IFNULL(ar.balance, 0) AS ar_balance
FROM sales s
LEFT JOIN customers c ON s.customer_id = c.customer_id
LEFT JOIN account_receivable ar ON ar.sales_id = s.sale_id
{$where}
ORDER BY s.sale_date DESC, s.sale_id DESC
LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $conn->prepare($listSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$salesRes = $stmt->get_result();
$stmt->close();

/* =========================
If view details requested
========================= */
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$saleInfo = null;
$items = null;

$items = [];

if($view_id > 0){
// Ensure cashier can only view own sales
$stmt = $conn->prepare("
SELECT s.*, c.first_name, c.last_name
FROM sales s
LEFT JOIN customers c ON s.customer_id = c.customer_id
WHERE s.sale_id=? AND s.user_id=?
LIMIT 1
");
$stmt->bind_param("ii", $view_id, $user_id);
$stmt->execute();
$saleInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if($saleInfo){
$stmt = $conn->prepare("
SELECT si.*, p.variety, p.grade
FROM sales_items si
LEFT JOIN products p ON si.product_id = p.product_id
WHERE si.sale_id=?
ORDER BY si.sales_item_id ASC
");
$stmt->bind_param("i", $view_id);
$stmt->execute();
$itemsRes = $stmt->get_result();
while($r = $itemsRes->fetch_assoc()) $items[] = $r;
$stmt->close();
}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sales History | Cashier</title>

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
<h3 class="fw-bold mb-1">Sales History</h3>
<div class="text-muted">View your recorded sales and details.</div>
</div>
<a class="btn btn-dark" href="pos.php"><i class="fa-solid fa-plus me-1"></i> New Sale</a>
</div>

<!-- FILTERS -->
<div class="card modern-card mb-3">
<div class="card-body">
<form class="row g-2 align-items-end" method="GET">
<div class="col-12 col-md-4">
<label class="form-label">Search (Sale ID / Customer)</label>
<input class="form-control" name="q" value="<?= h($q) ?>" placeholder="e.g. 101 or Juan">
</div>
<div class="col-6 col-md-2">
<label class="form-label">From</label>
<input type="date" class="form-control" name="date_from" value="<?= h($date_from) ?>">
</div>
<div class="col-6 col-md-2">
<label class="form-label">To</label>
<input type="date" class="form-control" name="date_to" value="<?= h($date_to) ?>">
</div>
<div class="col-12 col-md-2">
<label class="form-label">Status</label>
<select class="form-select" name="status">
<option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
<option value="paid" <?= $status==='paid'?'selected':'' ?>>Paid</option>
<option value="unpaid" <?= $status==='unpaid'?'selected':'' ?>>Unpaid (Utang)</option>
<option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
</select>
</div>
<div class="col-12 col-md-2 d-grid">
<button class="btn btn-outline-dark"><i class="fa-solid fa-filter me-1"></i> Filter</button>
</div>
</form>
</div>
</div>

<div class="row g-4">
<!-- SALES TABLE -->
<div class="col-12 col-xl-7">
<div class="card modern-card">
<div class="card-body table-responsive">
<table class="table table-striped align-middle mb-0">
<thead class="table-dark">
<tr>
<th>Sale #</th>
<th>Date</th>
<th>Customer</th>
<th>Total (₱)</th>
<th>Status</th>
<th>Balance</th>
<th></th>
</tr>
</thead>
<tbody>
<?php if($salesRes && $salesRes->num_rows > 0): ?>
<?php while($s = $salesRes->fetch_assoc()): ?>
<?php
$cust = trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? ''));
if($cust === '') $cust = 'N/A';
$st = strtolower(trim($s['status'] ?? ''));
?>
<tr>
<td class="fw-bold"><?= (int)$s['sale_id'] ?></td>
<td><?= $s['sale_date'] ? date("M d, Y h:i A", strtotime($s['sale_date'])) : '' ?></td>
<td><?= h($cust) ?></td>
<td><?= number_format((float)$s['total_amount'],2) ?></td>
<td>
<?php if($st === 'paid'): ?>
<span class="badge bg-success">PAID</span>
<?php elseif($st === 'unpaid'): ?>
<span class="badge bg-warning text-dark">UNPAID</span>
<?php elseif($st === 'cancelled'): ?>
<span class="badge bg-secondary">CANCELLED</span>
<?php else: ?>
<span class="badge bg-dark"><?= h(strtoupper($st ?: 'N/A')) ?></span>
<?php endif; ?>
</td>
<td>
<?php
$bal = (float)($s['ar_balance'] ?? 0);
echo $bal > 0 ? ('₱'.number_format($bal,2)) : '-';
?>
</td>
<td class="text-end">
<a class="btn btn-sm btn-outline-dark" href="sales_history.php?<?= http_build_query(array_merge($_GET, ['view'=>$s['sale_id']])) ?>">
<i class="fa-solid fa-eye"></i>
</a>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="7" class="text-center text-muted py-4">No sales found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<!-- Pagination -->
<?php if($totalPages > 1): ?>
<nav class="mt-3">
<ul class="pagination">
<?php
$base = $_GET;
for($p=1; $p<=$totalPages; $p++):
$base['page'] = $p;
$link = 'sales_history.php?'.http_build_query($base);
?>
<li class="page-item <?= $p===$page?'active':'' ?>">
<a class="page-link" href="<?= h($link) ?>"><?= $p ?></a>
</li>
<?php endfor; ?>
</ul>
</nav>
<?php endif; ?>
</div>

<!-- DETAILS PANEL -->
<div class="col-12 col-xl-5">
<div class="card modern-card">
<div class="card-body">
<h5 class="fw-bold mb-2">Sale Details</h5>

<?php if(!$saleInfo): ?>
<div class="text-muted">Select a sale on the left to view details.</div>
<?php else: ?>
<div class="mb-2">
<div class="text-muted small">Sale #</div>
<div class="h4 mb-0"><?= (int)$saleInfo['sale_id'] ?></div>
</div>

<div class="mb-2">
<div class="text-muted small">Customer</div>
<div class="fw-semibold">
<?= h(trim(($saleInfo['first_name'] ?? '').' '.($saleInfo['last_name'] ?? '')) ?: 'N/A') ?>
</div>
</div>

<div class="mb-2">
<div class="text-muted small">Date</div>
<div><?= $saleInfo['sale_date'] ? date("M d, Y h:i A", strtotime($saleInfo['sale_date'])) : '' ?></div>
</div>

<div class="mb-3">
<div class="text-muted small">Total</div>
<div class="h5 fw-bold">₱<?= number_format((float)$saleInfo['total_amount'],2) ?></div>
</div>

<div class="table-responsive">
<table class="table table-sm align-middle mb-0">
<thead class="table-dark">
<tr>
<th>Item</th>
<th class="text-end">Qty</th>
<th class="text-end">Price</th>
<th class="text-end">Line</th>
</tr>
</thead>
<tbody>
<?php if(count($items) > 0): ?>
<?php foreach($items as $it): ?>
<tr>
<td><?= h(($it['variety'] ?? 'N/A').' - '.($it['grade'] ?? '')) ?></td>
<td class="text-end"><?= number_format((float)$it['qty_kg'],2) ?> kg</td>
<td class="text-end">₱<?= number_format((float)$it['unit_price'],2) ?></td>
<td class="text-end">₱<?= number_format((float)$it['line_total'],2) ?></td>
</tr>
<?php endforeach; ?>
<?php else: ?>
<tr><td colspan="4" class="text-center text-muted">No items found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<div class="mt-3">
<a class="btn btn-outline-dark w-100" href="payments.php?sale_id=<?= (int)$saleInfo['sale_id'] ?>">
<i class="fa-solid fa-hand-holding-dollar me-1"></i> Record Payment (if Utang)
</a>
</div>
<?php endif; ?>
</div>
</div>
</div>

</div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>