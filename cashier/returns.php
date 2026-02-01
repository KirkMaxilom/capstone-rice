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
ENHANCEMENTS ADDED:
1) Auto-load products by Sale (AJAX) -> no manual product_id typing
2) Show max returnable qty (UI + still enforced server-side)
3) Reason modal (view full reason)
*/

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

/* =========================
AJAX: Load Products from a Sale
Returns: [{product_id, label, sold_qty, returned_qty, max_qty}]
========================= */
if(isset($_GET['ajax']) && $_GET['ajax'] === 'sale_items'){
$sale_id = (int)($_GET['sale_id'] ?? 0);

// Ensure sale belongs to this cashier
$stmt = $conn->prepare("SELECT sale_id FROM sales WHERE sale_id=? AND user_id=? LIMIT 1");
if(!$stmt){
header('Content-Type: application/json');
echo json_encode(['error' => 'Prepare error: '.$conn->error]);
exit;
}
$stmt->bind_param("ii", $sale_id, $user_id);
$stmt->execute();
$owned = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$owned){
header('Content-Type: application/json');
echo json_encode([]);
exit;
}

// Load products from sales_items + compute max returnable
$sql = "
SELECT
si.product_id,
CONCAT(p.variety,' - ',p.grade) AS label,
IFNULL(SUM(si.qty_kg),0) AS sold_qty,
IFNULL((
SELECT SUM(r.qty_returned)
FROM returns r
WHERE r.sale_id = si.sale_id
AND r.product_id = si.product_id
AND LOWER(r.status) IN ('pending','approved')
),0) AS returned_qty
FROM sales_items si
JOIN products p ON p.product_id = si.product_id
WHERE si.sale_id = ?
GROUP BY si.product_id
ORDER BY label ASC
";

$stmt = $conn->prepare($sql);
if(!$stmt){
header('Content-Type: application/json');
echo json_encode(['error' => 'Prepare error: '.$conn->error]);
exit;
}
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while($r = $res->fetch_assoc()){
$sold = (float)$r['sold_qty'];
$ret = (float)$r['returned_qty'];
$max = max(0, $sold - $ret);

// Only show if there is still returnable qty
if($max > 0){
$out[] = [
'product_id' => (int)$r['product_id'],
'label' => $r['label'],
'sold_qty' => $sold,
'returned_qty' => $ret,
'max_qty' => $max
];
}
}

$stmt->close();
header('Content-Type: application/json');
echo json_encode($out);
exit;
}

/* =========================
Create Return Request
========================= */
if(isset($_POST['create_return'])){
$sale_id = (int)($_POST['sale_id'] ?? 0);
$product_id= (int)($_POST['product_id'] ?? 0);
$qty = (float)($_POST['qty_returned'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if($sale_id<=0 || $product_id<=0 || $qty<=0 || $reason===''){
header("Location: returns.php?error=" . urlencode("Please fill up all fields correctly."));
exit;
}

// Make sure this sale belongs to the cashier
$stmt = $conn->prepare("SELECT sale_id FROM sales WHERE sale_id=? AND user_id=? LIMIT 1");
if(!$stmt){ die("SQL PREPARE ERROR: ".$conn->error); }
$stmt->bind_param("ii", $sale_id, $user_id);
$stmt->execute();
$owned = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$owned){
header("Location: returns.php?error=" . urlencode("You can only file returns for your own sales."));
exit;
}

// Validate product exists in that sale + qty not exceeding sold qty
$stmt = $conn->prepare("
SELECT IFNULL(SUM(qty_kg),0) AS sold_qty
FROM sales_items
WHERE sale_id=? AND product_id=?
");
if(!$stmt){ die("SQL PREPARE ERROR: ".$conn->error); }
$stmt->bind_param("ii", $sale_id, $product_id);
$stmt->execute();
$sold = (float)($stmt->get_result()->fetch_assoc()['sold_qty'] ?? 0);
$stmt->close();

if($sold <= 0){
header("Location: returns.php?error=" . urlencode("This product is not part of Sale #{$sale_id}."));
exit;
}

// Compute already-returned (approved + pending) to prevent exceeding sold
$stmt = $conn->prepare("
SELECT IFNULL(SUM(qty_returned),0) AS returned_qty
FROM returns
WHERE sale_id=? AND product_id=? AND LOWER(status) IN ('pending','approved')
");
if(!$stmt){ die("SQL PREPARE ERROR: ".$conn->error); }
$stmt->bind_param("ii", $sale_id, $product_id);
$stmt->execute();
$already = (float)($stmt->get_result()->fetch_assoc()['returned_qty'] ?? 0);
$stmt->close();

$maxAllowed = max(0, $sold - $already);
if($qty > $maxAllowed){
header("Location: returns.php?error=" . urlencode("Qty exceeds allowed return. Max allowed: ".number_format($maxAllowed,2)." kg"));
exit;
}

// Insert return request
$stmt = $conn->prepare("
INSERT INTO returns (sale_id, product_id, qty_returned, reason, return_date, status)
VALUES (?, ?, ?, ?, NOW(), 'PENDING')
");
if(!$stmt){ die("SQL PREPARE ERROR: ".$conn->error); }
$stmt->bind_param("iids", $sale_id, $product_id, $qty, $reason);
$stmt->execute();
$stmt->close();

header("Location: returns.php?success=" . urlencode("Return request submitted (PENDING)."));
exit;
}

/* =========================
Data for dropdowns
========================= */
// List recent cashier sales
$sales = $conn->prepare("
SELECT s.sale_id, s.sale_date, c.first_name, c.last_name
FROM sales s
LEFT JOIN customers c ON s.customer_id = c.customer_id
WHERE s.user_id=?
ORDER BY s.sale_date DESC
LIMIT 50
");
if(!$sales){ die("SQL PREPARE ERROR: ".$conn->error); }
$sales->bind_param("i", $user_id);
$sales->execute();
$salesRes = $sales->get_result();
$sales->close();

/* =========================
Return Requests List
========================= */
$q = trim($_GET['q'] ?? '');
$filter_status = strtolower(trim($_GET['status'] ?? 'all')); // all|pending|approved|rejected

$where = "WHERE s.user_id=?";
$params = [$user_id];
$types = "i";

if($q !== ''){
$where .= " AND (r.return_id LIKE CONCAT('%',?,'%') OR r.sale_id LIKE CONCAT('%',?,'%') OR p.variety LIKE CONCAT('%',?,'%'))";
$params[] = $q; $params[] = $q; $params[] = $q;
$types .= "sss";
}
if(in_array($filter_status, ['pending','approved','rejected'], true)){
$where .= " AND LOWER(r.status)=?";
$params[] = $filter_status;
$types .= "s";
}

$listSql = "
SELECT
r.return_id, r.sale_id, r.product_id, r.qty_returned, r.reason, r.return_date, r.status,
p.variety, p.grade,
c.first_name, c.last_name
FROM returns r
JOIN sales s ON r.sale_id = s.sale_id
LEFT JOIN products p ON r.product_id = p.product_id
LEFT JOIN customers c ON s.customer_id = c.customer_id
{$where}
ORDER BY r.return_date DESC, r.return_id DESC
";

$stmt = $conn->prepare($listSql);
if(!$stmt){ die("SQL PREPARE ERROR: ".$conn->error."<br><pre>$listSql</pre>"); }
$stmt->bind_param($types, ...$params);
$stmt->execute();
$returnsRes = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Returns | Cashier</title>

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
<h3 class="fw-bold mb-1">Returns</h3>
<div class="text-muted">File return requests. Owner will approve and update inventory.</div>
</div>
</div>

<?php if($success): ?><div class="alert alert-success py-2"><?= h($success) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>

<div class="row g-4">

<!-- CREATE RETURN -->
<div class="col-12 col-xl-4">
<div class="card modern-card">
<div class="card-body">
<h5 class="fw-bold mb-3">Create Return Request</h5>

<form method="POST" id="returnForm">
<input type="hidden" name="create_return" value="1">

<div class="mb-3">
<label class="form-label">Sale</label>
<select class="form-select" name="sale_id" id="saleSelect" required>
<option value="">Select Sale</option>
<?php while($s = $salesRes->fetch_assoc()): ?>
<?php
$cust = trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? ''));
if($cust === '') $cust = 'N/A';
$label = "#".$s['sale_id']." — ".$cust." — ".date("M d, Y", strtotime($s['sale_date']));
?>
<option value="<?= (int)$s['sale_id'] ?>"><?= h($label) ?></option>
<?php endwhile; ?>
</select>
<div class="small-muted mt-1">Tip: Pick the correct sale first.</div>
</div>

<!-- ENHANCED: Product dropdown instead of manual product_id -->
<div class="mb-3">
<label class="form-label">Product (from Sale Items)</label>
<select class="form-select" name="product_id" id="productSelect" required disabled>
<option value="">Select Sale first</option>
</select>
<div class="small-muted mt-1" id="maxHint" style="display:none;">
Max returnable: <b id="maxQtyText">0</b> kg
</div>
</div>

<div class="mb-3">
<label class="form-label">Qty Returned (kg)</label>
<input type="number" step="0.01" min="0" class="form-control" name="qty_returned" id="qtyInput" required>
</div>

<div class="mb-3">
<label class="form-label">Reason</label>
<textarea class="form-control" name="reason" rows="3" required placeholder="e.g. Damaged packaging"></textarea>
</div>

<button class="btn btn-dark w-100">
<i class="fa-solid fa-paper-plane me-1"></i> Submit (PENDING)
</button>

</form>
</div>
</div>
</div>

<!-- RETURNS LIST -->
<div class="col-12 col-xl-8">
<div class="card modern-card mb-3">
<div class="card-body">
<form class="row g-2 align-items-end" method="GET">
<div class="col-12 col-md-6">
<label class="form-label">Search (Return ID / Sale ID / Variety)</label>
<input class="form-control" name="q" value="<?= h($q) ?>" placeholder="e.g. 10 or Jasmine">
</div>
<div class="col-12 col-md-3">
<label class="form-label">Status</label>
<select class="form-select" name="status">
<option value="all" <?= $filter_status==='all'?'selected':'' ?>>All</option>
<option value="pending" <?= $filter_status==='pending'?'selected':'' ?>>Pending</option>
<option value="approved" <?= $filter_status==='approved'?'selected':'' ?>>Approved</option>
<option value="rejected" <?= $filter_status==='rejected'?'selected':'' ?>>Rejected</option>
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
<th>Return #</th>
<th>Sale #</th>
<th>Customer</th>
<th>Product</th>
<th>Qty</th>
<th>Date</th>
<th>Status</th>
<th>Reason</th>
</tr>
</thead>
<tbody>
<?php if($returnsRes && $returnsRes->num_rows > 0): ?>
<?php while($r = $returnsRes->fetch_assoc()): ?>
<?php
$cust = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
if($cust === '') $cust = 'N/A';
$prod = trim(($r['variety'] ?? 'N/A') . " - " . ($r['grade'] ?? ''));
$st = strtolower(trim($r['status'] ?? 'pending'));
$reasonFull = (string)($r['reason'] ?? '');
?>
<tr>
<td class="fw-bold"><?= (int)$r['return_id'] ?></td>
<td><?= (int)$r['sale_id'] ?></td>
<td><?= h($cust) ?></td>
<td><?= h($prod) ?></td>
<td class="fw-bold"><?= number_format((float)$r['qty_returned'],2) ?> kg</td>
<td><?= $r['return_date'] ? date("M d, Y h:i A", strtotime($r['return_date'])) : '' ?></td>
<td>
<?php if($st==='approved'): ?>
<span class="badge bg-success">APPROVED</span>
<?php elseif($st==='rejected'): ?>
<span class="badge bg-danger">REJECTED</span>
<?php else: ?>
<span class="badge bg-warning text-dark">PENDING</span>
<?php endif; ?>
</td>
<td style="max-width:320px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
<?= h($reasonFull) ?>
<?php if(mb_strlen($reasonFull) > 35): ?>
<button
type="button"
class="btn btn-sm btn-link p-0 ms-2"
data-bs-toggle="modal"
data-bs-target="#reasonModal"
data-reason="<?= h($reasonFull) ?>"
>View</button>
<?php endif; ?>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="8" class="text-center text-muted py-4">No return requests found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>

<div class="small-muted mt-2">
Note: Only Admin/Manager approves returns → then inventory logs will record an <b>IN</b> transaction.
</div>
</div>

</div>
</main>

<!-- Reason Modal (Bootstrap) -->
<div class="modal fade" id="reasonModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title">Return Reason</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" id="reasonBody" style="white-space:pre-wrap;"></div>
<div class="modal-footer">
<button type="button" class="btn btn-dark" data-bs-dismiss="modal">Close</button>
</div>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const saleSelect = document.getElementById('saleSelect');
const productSelect = document.getElementById('productSelect');
const qtyInput = document.getElementById('qtyInput');
const maxHint = document.getElementById('maxHint');
const maxQtyText = document.getElementById('maxQtyText');

let productMeta = {}; // product_id => {max_qty}

saleSelect.addEventListener('change', async () => {
const saleId = saleSelect.value;

productSelect.innerHTML = '<option value="">Loading...</option>';
productSelect.disabled = true;
maxHint.style.display = 'none';
qtyInput.value = '';
qtyInput.removeAttribute('max');

if(!saleId){
productSelect.innerHTML = '<option value="">Select Sale first</option>';
return;
}

const res = await fetch(`returns.php?ajax=sale_items&sale_id=${encodeURIComponent(saleId)}`);
const data = await res.json();

productMeta = {};
if(!Array.isArray(data) || data.length === 0){
productSelect.innerHTML = '<option value="">No returnable items for this sale</option>';
productSelect.disabled = true;
return;
}

productSelect.innerHTML = '<option value="">Select Product</option>';
data.forEach(item => {
productMeta[item.product_id] = { max_qty: Number(item.max_qty || 0) };
const opt = document.createElement('option');
opt.value = item.product_id;
opt.textContent = `${item.label} (max ${Number(item.max_qty).toFixed(2)} kg)`;
productSelect.appendChild(opt);
});

productSelect.disabled = false;
});

productSelect.addEventListener('change', () => {
const pid = productSelect.value;
maxHint.style.display = 'none';
qtyInput.value = '';
qtyInput.removeAttribute('max');

if(!pid || !productMeta[pid]) return;

const mx = productMeta[pid].max_qty;
maxQtyText.textContent = mx.toFixed(2);
maxHint.style.display = 'block';

// Client-side guard (server-side still enforced)
qtyInput.setAttribute('max', mx.toFixed(2));
});

qtyInput.addEventListener('input', () => {
const max = Number(qtyInput.getAttribute('max') || 0);
const v = Number(qtyInput.value || 0);
if(max > 0 && v > max){
qtyInput.value = max.toFixed(2);
}
});

// Reason modal handler
const reasonModal = document.getElementById('reasonModal');
reasonModal.addEventListener('show.bs.modal', (event) => {
const btn = event.relatedTarget;
const reason = btn.getAttribute('data-reason') || '';
document.getElementById('reasonBody').textContent = reason;
});
</script>

</body>
</html>
