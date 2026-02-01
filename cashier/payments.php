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
CASHIER PAYMENTS (Utang)
- Lists account_receivable (unpaid/partial)
- Allows cashier to record payment in payments table
- Updates:
account_receivable.amount_paid, balance, status
sales.status (paid if fully paid)
Notes:
- Your schema:
payments(payment_id, sale_id, amount, method, status, paid_at, external_ref)
account_receivable(ar_id, sales_id, customer_id, total_amount, amount_paid, balance, due_date, status, created_at)
- We use "due_date" exactly as your column name.
*/

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

/* =========================
Handle Add Payment
========================= */
if(isset($_POST['add_payment'])){
$sale_id = (int)($_POST['sale_id'] ?? 0);
$amount = (float)($_POST['amount'] ?? 0);
$method = trim($_POST['method'] ?? 'cash');
$ext_ref = trim($_POST['external_ref'] ?? '');

if($sale_id <= 0 || $amount <= 0){
header("Location: payments.php?error=" . urlencode("Invalid sale or amount."));
exit;
}

// Make sure sale belongs to this cashier (optional but good)
$stmt = $conn->prepare("SELECT sale_id FROM sales WHERE sale_id=? AND user_id=? LIMIT 1");
$stmt->bind_param("ii", $sale_id, $user_id);
$stmt->execute();
$owned = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$owned){
header("Location: payments.php?error=" . urlencode("You can only record payments for your own sales."));
exit;
}

$conn->begin_transaction();
try{
// Get AR record
$stmt = $conn->prepare("SELECT ar_id, total_amount, amount_paid, balance, status FROM account_receivable WHERE sales_id=? LIMIT 1");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$ar = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$ar){
throw new Exception("No account receivable found for Sale #{$sale_id}.");
}

$balance = (float)$ar['balance'];

if($balance <= 0){
throw new Exception("Sale #{$sale_id} is already fully paid.");
}

// Prevent overpayment
if($amount > $balance){
throw new Exception("Amount exceeds balance. Balance is ₱".number_format($balance,2));
}

// Insert payment
$payStatus = "paid";
$stmt = $conn->prepare("INSERT INTO payments (sale_id, amount, method, status, paid_at, external_ref)
VALUES (?, ?, ?, ?, NOW(), ?)");
$stmt->bind_param("idsss", $sale_id, $amount, $method, $payStatus, $ext_ref);
$stmt->execute();
$stmt->close();

$message = "Payment received for Sale #{$sale_id}: ₱".number_format($amount,2).". Thank you!";
$stmt = $conn -> prepare("INSERT INTO push_notif_logs (payment_id, customer_id, message, sent_at, status) VALUES (NULL, (SELECT customer_id FROM account_receivable WHERE sales_id=? LIMIT 1), ?, NOW(), 'SENT')");
$stmt -> bind_param("is", $sale_id, $message);
$stmt -> execute();
$stmt -> close();

// Update AR totals
$new_paid = (float)$ar['amount_paid'] + $amount;
$new_bal = (float)$ar['balance'] - $amount;

$new_status = ($new_bal <= 0.00001) ? "paid" : "partial";

$stmt = $conn->prepare("UPDATE account_receivable
SET amount_paid=?, balance=?, status=?
WHERE sales_id=?");
$stmt->bind_param("ddsi", $new_paid, $new_bal, $new_status, $sale_id);
$stmt->execute();
$stmt->close();

// Update sales.status if fully paid
if($new_status === "paid"){
$stmt = $conn->prepare("UPDATE sales SET status='paid' WHERE sale_id=?");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$stmt->close();
} else {
// keep sales as unpaid
$stmt = $conn->prepare("UPDATE sales SET status='unpaid' WHERE sale_id=?");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$stmt->close();
}

$conn->commit();

$desc ="Payment received for Sale #{$sale_id} amount ₱".number_format($amount,2);
$stmt = $conn->prepare("INSERT INTO activity_logs (user_id, activity_type, description, created_at) VALUES (?, 'PAYMENT_RECEIVED', ?, NOW())");
$stmt->bind_param("is", $user_id, $desc);
$stmt->execute();
$stmt->close();

header("Location: payments.php?success=" . urlencode("Payment recorded for Sale #{$sale_id}."));
exit;

} catch(Exception $e){
$conn->rollback();
header("Location: payments.php?error=" . urlencode($e->getMessage()));
exit;
}
}

/* =========================
Filters
========================= */
$q = trim($_GET['q'] ?? '');
$filter_status = strtolower(trim($_GET['status'] ?? 'open')); // open|all|paid

$where = "WHERE 1=1";
$params = [];
$types = "";

// Only show sales of this cashier
$where .= " AND s.user_id = ?";
$params[] = $user_id;
$types .= "i";

if($q !== ''){
$where .= " AND (ar.sales_id LIKE CONCAT('%',?,'%') OR c.first_name LIKE CONCAT('%',?,'%') OR c.last_name LIKE CONCAT('%',?,'%'))";
$params[] = $q; $params[] = $q; $params[] = $q;
$types .= "sss";
}

if($filter_status === 'open'){
$where .= " AND LOWER(IFNULL(ar.status,'')) IN ('unpaid','partial')";
} elseif($filter_status === 'paid'){
$where .= " AND LOWER(IFNULL(ar.status,'')) = 'paid'";
} // all -> no extra

/* =========================
Receivables list
========================= */
$sql = "
SELECT
ar.ar_id, ar.sales_id, ar.customer_id, ar.total_amount, ar.amount_paid, ar.balance, ar.due_date, ar.status AS ar_status, ar.created_at,
s.sale_date, s.total_amount AS sale_total, s.status AS sale_status,
c.first_name, c.last_name, c.phone
FROM account_receivable ar
JOIN sales s ON ar.sales_id = s.sale_id
LEFT JOIN customers c ON ar.customer_id = c.customer_id
{$where}
ORDER BY ar.created_at DESC
";
$stmt = $conn->prepare($sql);
if($types !== ""){
$stmt->bind_param($types, ...$params);
}
$stmt->execute();
$ars = $stmt->get_result();
$stmt->close();

/* =========================
If sale preselected
========================= */
$prefill_sale_id = (int)($_GET['sale_id'] ?? 0);
$prefill = null;
if($prefill_sale_id > 0){
$stmt = $conn->prepare("
SELECT
ar.sales_id, ar.balance,
c.first_name, c.last_name
FROM account_receivable ar
JOIN sales s ON ar.sales_id = s.sale_id
LEFT JOIN customers c ON ar.customer_id = c.customer_id
WHERE ar.sales_id=? AND s.user_id=?
LIMIT 1
");
$stmt->bind_param("ii", $prefill_sale_id, $user_id);
$stmt->execute();
$prefill = $stmt->get_result()->fetch_assoc();
$stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Utang Payments | Cashier</title>

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
<h3 class="fw-bold mb-1">Utang Payments</h3>
<div class="text-muted">Record payments and reduce customer balance.</div>
</div>
</div>

<?php if($success): ?>
<div class="alert alert-success py-2"><?= h($success) ?></div>
<?php endif; ?>
<?php if($error): ?>
<div class="alert alert-danger py-2"><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-4">

<!-- LEFT: ADD PAYMENT -->
<div class="col-12 col-xl-4">
<div class="card modern-card">
<div class="card-body">
<h5 class="fw-bold mb-3">Record Payment</h5>

<?php if($prefill): ?>
<div class="alert alert-info py-2 small">
Selected Sale #<?= (int)$prefill['sales_id'] ?> —
<?= h(trim(($prefill['first_name'] ?? '').' '.($prefill['last_name'] ?? '')) ?: 'Customer') ?> —
Balance: <b>₱<?= number_format((float)$prefill['balance'],2) ?></b>
</div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="add_payment" value="1">

<div class="mb-3">
<label class="form-label">Sale ID</label>
<input type="number" class="form-control" name="sale_id" required
value="<?= $prefill ? (int)$prefill['sales_id'] : '' ?>"
placeholder="e.g. 123">
</div>

<div class="mb-3">
<label class="form-label">Amount (₱)</label>
<input type="number" step="0.01" min="0" class="form-control" name="amount" required
placeholder="e.g. 500.00">
<div class="small-muted mt-1">System blocks overpayment automatically.</div>
</div>

<div class="mb-3">
<label class="form-label">Method</label>
<select class="form-select" name="method" required>
<option value="cash">Cash</option>
<option value="gcash">GCash</option>
<option value="bank">Bank Transfer</option>
<option value="other">Other</option>
</select>
</div>

<div class="mb-3">
<label class="form-label">External Ref (optional)</label>
<input class="form-control" name="external_ref" placeholder="GCash ref, bank ref, etc.">
</div>

<button class="btn btn-dark w-100">
<i class="fa-solid fa-check me-1"></i> Save Payment
</button>
</form>

</div>
</div>
</div>

<!-- RIGHT: RECEIVABLES LIST -->
<div class="col-12 col-xl-8">
<div class="card modern-card mb-3">
<div class="card-body">
<form class="row g-2 align-items-end" method="GET">
<div class="col-12 col-md-6">
<label class="form-label">Search (Sale ID / Customer)</label>
<input class="form-control" name="q" value="<?= h($q) ?>" placeholder="e.g. 100 or Maria">
</div>
<div class="col-12 col-md-3">
<label class="form-label">Status</label>
<select class="form-select" name="status">
<option value="open" <?= $filter_status==='open'?'selected':'' ?>>Open (Unpaid/Partial)</option>
<option value="all" <?= $filter_status==='all'?'selected':'' ?>>All</option>
<option value="paid" <?= $filter_status==='paid'?'selected':'' ?>>Paid</option>
</select>
</div>
<div class="col-12 col-md-3 d-grid">
<button class="btn btn-outline-dark"><i class="fa-solid fa-filter me-1"></i> Filter</button>
</div>
</form>
</div>
</div>

<div class="card modern-card">
<div class="card-body table-responsive">
<table class="table table-striped align-middle mb-0">
<thead class="table-dark">
<tr>
<th>Sale #</th>
<th>Customer</th>
<th>Total</th>
<th>Paid</th>
<th>Balance</th>
<th>Due</th>
<th>Status</th>
<th></th>
</tr>
</thead>
<tbody>
<?php if($ars && $ars->num_rows > 0): ?>
<?php while($r = $ars->fetch_assoc()): ?>
<?php
$cust = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
if($cust === '') $cust = 'N/A';
$st = strtolower(trim($r['ar_status'] ?? ''));
$bal = (float)($r['balance'] ?? 0);
?>
<tr>
<td class="fw-bold"><?= (int)$r['sales_id'] ?></td>
<td><?= h($cust) ?></td>
<td>₱<?= number_format((float)$r['total_amount'],2) ?></td>
<td>₱<?= number_format((float)$r['amount_paid'],2) ?></td>
<td class="<?= $bal>0 ? 'fw-bold text-danger' : 'text-muted' ?>">
<?= $bal>0 ? ('₱'.number_format($bal,2)) : '₱0.00' ?>
</td>
<td><?= $r['due_date'] ? h($r['due_date']) : '-' ?></td>
<td>
<?php if($st === 'paid'): ?>
<span class="badge bg-success">PAID</span>
<?php elseif($st === 'partial'): ?>
<span class="badge bg-warning text-dark">PARTIAL</span>
<?php else: ?>
<span class="badge bg-danger">UNPAID</span>
<?php endif; ?>
</td>
<td class="text-end">
<a class="btn btn-sm btn-outline-dark" href="payments.php?sale_id=<?= (int)$r['sales_id'] ?>">
<i class="fa-solid fa-arrow-up-right-from-square"></i>
</a>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="8" class="text-center text-muted py-4">No receivables found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<div class="small-muted mt-2">
Tip: “Open” means unpaid/partial. Fully paid receivables automatically update sales.status to <b>paid</b>.
</div>
</div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>