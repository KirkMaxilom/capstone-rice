<?php
session_start();
if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'owner'){ header("Location: ../login.php"); exit; }

$username = $_SESSION['username'] ?? 'Owner';
include '../config/db.php';

$days = (int)($_GET['days'] ?? 30);
if(!in_array($days, [7,30,90])) $days = 30;

$ar_status = strtolower(trim($_GET['ar_status'] ?? 'all'));
$ap_status = strtolower(trim($_GET['ap_status'] ?? 'all'));

function badge_for_status($st){
  $st = strtolower(trim($st));
  if($st==='paid' || $st==='settled' || $st==='completed') return 'bg-success';
  if($st==='partial') return 'bg-info';
  if($st==='pending' || $st==='unpaid') return 'bg-warning text-dark';
  if($st==='overdue') return 'bg-danger';
  return 'bg-secondary';
}

/* =========================
   AR - Accounts Receivable
   NOTE: uses dude_date per your DB list
========================= */
$arWhere = "WHERE ar.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
if($ar_status !== 'all'){
  $ar_status_esc = $conn->real_escape_string($ar_status);
  $arWhere .= " AND LOWER(IFNULL(ar.status,'')) = '$ar_status_esc'";
}

$ar = $conn->query("
  SELECT
    ar.ar_id, ar.sales_id, ar.customer_id,
    ar.total_amount, ar.amount_paid, ar.balance,
    ar.dude_date, ar.status, ar.created_at,
    CONCAT(c.first_name,' ',c.last_name) AS customer_name
  FROM account_receivable ar
  LEFT JOIN customers c ON c.customer_id = ar.customer_id
  $arWhere
  ORDER BY ar.created_at DESC
  LIMIT 300
");

$arSum = $conn->query("
  SELECT
    COALESCE(SUM(total_amount),0) AS total_amt,
    COALESCE(SUM(amount_paid),0) AS total_paid,
    COALESCE(SUM(balance),0) AS total_bal
  FROM account_receivable ar
  $arWhere
")->fetch_assoc();

/* =========================
   AP - Accounts Payable
========================= */
$apWhere = "WHERE ap.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
if($ap_status !== 'all'){
  $ap_status_esc = $conn->real_escape_string($ap_status);
  $apWhere .= " AND LOWER(IFNULL(ap.status,'')) = '$ap_status_esc'";
}

$ap = $conn->query("
  SELECT
    ap.ap_id, ap.purchase_id, ap.supplier_id,
    ap.total_amount, ap.amount_paid, ap.balance,
    ap.due_date, ap.status, ap.created_at,
    s.name AS supplier_name
  FROM account_payable ap
  LEFT JOIN suppliers s ON s.supplier_id = ap.supplier_id
  $apWhere
  ORDER BY ap.created_at DESC
  LIMIT 300
");

$apSum = $conn->query("
  SELECT
    COALESCE(SUM(total_amount),0) AS total_amt,
    COALESCE(SUM(amount_paid),0) AS total_paid,
    COALESCE(SUM(balance),0) AS total_bal
  FROM account_payable ap
  $apWhere
")->fetch_assoc();

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AR / AP Monitoring | Owner</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }
.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); }
.main-content { padding-top:70px; padding-left: 20px; padding-right: 20px; }
</style>
</head>

<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<!-- TOP NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top" style="margin-left: 260px; width: calc(100% - 260px); z-index: 1020;">
  <div class="container-fluid">
    <span class="navbar-brand fw-bold ms-2">DO HIVES GENERAL MERCHANDISE</span>

    <div class="ms-auto dropdown">
      <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
        <?= htmlspecialchars($username) ?> <small class="text-muted">(Owner)</small>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- MAIN -->
<main class="main-content">
<div class="container-fluid">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h3 class="fw-bold mb-1">AR / AP Monitoring</h3>
      <div class="text-muted">Track balances: customer receivables and supplier payables.</div>
    </div>

    <form method="get" class="d-flex gap-2 align-items-center">
      <select class="form-select" name="days" onchange="this.form.submit()">
        <option value="7"  <?= $days===7?'selected':'' ?>>Last 7 days</option>
        <option value="30" <?= $days===30?'selected':'' ?>>Last 30 days</option>
        <option value="90" <?= $days===90?'selected':'' ?>>Last 90 days</option>
      </select>
    </form>
  </div>

  <!-- SUMMARY -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-xl-6">
      <div class="card modern-card">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div>
              <div class="text-muted">Accounts Receivable (AR) Balance</div>
              <div class="h3 fw-bold mb-0">₱<?= number_format((float)($arSum['total_bal'] ?? 0),2) ?></div>
              <div class="small text-muted">
                Total: ₱<?= number_format((float)($arSum['total_amt'] ?? 0),2) ?> • Paid: ₱<?= number_format((float)($arSum['total_paid'] ?? 0),2) ?>
              </div>
            </div>
            <i class="fa-solid fa-hand-holding-dollar fa-2x text-dark"></i>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-6">
      <div class="card modern-card">
        <div class="card-body">
          <div class="d-flex justify-content-between">
            <div>
              <div class="text-muted">Accounts Payable (AP) Balance</div>
              <div class="h3 fw-bold mb-0">₱<?= number_format((float)($apSum['total_bal'] ?? 0),2) ?></div>
              <div class="small text-muted">
                Total: ₱<?= number_format((float)($apSum['total_amt'] ?? 0),2) ?> • Paid: ₱<?= number_format((float)($apSum['total_paid'] ?? 0),2) ?>
              </div>
            </div>
            <i class="fa-solid fa-file-invoice-dollar fa-2x text-dark"></i>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- TABS -->
  <div class="card modern-card">
    <div class="card-body">

      <ul class="nav nav-pills mb-3">
        <li class="nav-item">
          <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-ar">
            <i class="fa-solid fa-hand-holding-dollar me-1"></i> Accounts Receivable (AR)
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-ap">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Accounts Payable (AP)
          </button>
        </li>
      </ul>

      <div class="tab-content">

        <!-- AR -->
        <div class="tab-pane fade show active" id="tab-ar">
          <form method="get" class="row g-2 mb-2">
            <input type="hidden" name="days" value="<?= (int)$days ?>">
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">AR Status</label>
              <select class="form-select" name="ar_status" onchange="this.form.submit()">
                <?php
                  $opts = ['all'=>'All','partial'=>'Partial','unpaid'=>'Unpaid','settled'=>'Settled','overdue'=>'Overdue','pending'=>'Pending'];
                  foreach($opts as $k=>$v){
                    $sel = ($ar_status===$k) ? 'selected' : '';
                    echo "<option value='$k' $sel>$v</option>";
                  }
                ?>
              </select>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Date</th>
                  <th>AR #</th>
                  <th>Sale</th>
                  <th>Customer</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Paid</th>
                  <th class="text-end">Balance</th>
                  <th>Due</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
              <?php if($ar && $ar->num_rows>0): ?>
                <?php while($r=$ar->fetch_assoc()): ?>
                  <?php
                    $due = $r['dude_date'] ?? null;
                    $isOverdue = ($due && $due < $today && (float)$r['balance'] > 0);
                    $status = strtolower(trim($r['status'] ?? ''));
                    if($isOverdue) $status = 'overdue';
                    $badge = badge_for_status($status);
                  ?>
                  <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                    <td><?= htmlspecialchars(date("M d, Y", strtotime($r['created_at']))) ?></td>
                    <td class="fw-semibold">#<?= (int)$r['ar_id'] ?></td>
                    <td>#<?= (int)$r['sales_id'] ?></td>
                    <td><?= htmlspecialchars($r['customer_name'] ?: '—') ?></td>
                    <td class="text-end">₱<?= number_format((float)$r['total_amount'],2) ?></td>
                    <td class="text-end">₱<?= number_format((float)$r['amount_paid'],2) ?></td>
                    <td class="text-end fw-bold">₱<?= number_format((float)$r['balance'],2) ?></td>
                    <td><?= htmlspecialchars($due ?: '—') ?></td>
                    <td><span class="badge <?= $badge ?>"><?= strtoupper($status ?: 'N/A') ?></span></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center text-muted">No AR records found.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- AP -->
        <div class="tab-pane fade" id="tab-ap">
          <form method="get" class="row g-2 mb-2">
            <input type="hidden" name="days" value="<?= (int)$days ?>">
            <div class="col-12 col-md-4">
              <label class="form-label fw-semibold">AP Status</label>
              <select class="form-select" name="ap_status" onchange="this.form.submit()">
                <?php
                  $opts = ['all'=>'All','partial'=>'Partial','unpaid'=>'Unpaid','settled'=>'Settled','overdue'=>'Overdue','pending'=>'Pending'];
                  foreach($opts as $k=>$v){
                    $sel = ($ap_status===$k) ? 'selected' : '';
                    echo "<option value='$k' $sel>$v</option>";
                  }
                ?>
              </select>
            </div>
          </form>

          <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Date</th>
                  <th>AP #</th>
                  <th>Purchase</th>
                  <th>Supplier</th>
                  <th class="text-end">Total</th>
                  <th class="text-end">Paid</th>
                  <th class="text-end">Balance</th>
                  <th>Due</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
              <?php if($ap && $ap->num_rows>0): ?>
                <?php while($r=$ap->fetch_assoc()): ?>
                  <?php
                    $due = $r['due_date'] ?? null;
                    $isOverdue = ($due && $due < $today && (float)$r['balance'] > 0);
                    $status = strtolower(trim($r['status'] ?? ''));
                    if($isOverdue) $status = 'overdue';
                    $badge = badge_for_status($status);
                  ?>
                  <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                    <td><?= htmlspecialchars(date("M d, Y", strtotime($r['created_at']))) ?></td>
                    <td class="fw-semibold">#<?= (int)$r['ap_id'] ?></td>
                    <td>#<?= (int)$r['purchase_id'] ?></td>
                    <td><?= htmlspecialchars($r['supplier_name'] ?: '—') ?></td>
                    <td class="text-end">₱<?= number_format((float)$r['total_amount'],2) ?></td>
                    <td class="text-end">₱<?= number_format((float)$r['amount_paid'],2) ?></td>
                    <td class="text-end fw-bold">₱<?= number_format((float)$r['balance'],2) ?></td>
                    <td><?= htmlspecialchars($due ?: '—') ?></td>
                    <td><span class="badge <?= $badge ?>"><?= strtoupper($status ?: 'N/A') ?></span></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center text-muted">No AP records found.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- tab-content -->
    </div>
  </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
