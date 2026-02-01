<?php
// owner/supplier_payables.php  (ENHANCED)
// One page: Approve + Pay + View payment history + Filters + Search + Summary cards

session_start();
if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'owner'){ header("Location: ../login.php"); exit; }

include "../config/db.php";

$username = $_SESSION['username'] ?? 'Owner';
$owner_id = (int)$_SESSION['user_id'];

$success = "";
$error   = "";

/* =========================
   Helpers: schema detection
========================= */
function tableExists(mysqli $conn, string $table): bool {
  $tableEsc = $conn->real_escape_string($table);
  $dbRes = $conn->query("SELECT DATABASE() AS dbname");
  $db = $dbRes ? ($dbRes->fetch_assoc()['dbname'] ?? '') : '';
  $dbEsc = $conn->real_escape_string($db);
  $q = $conn->query("
    SELECT 1
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = '$dbEsc' AND TABLE_NAME = '$tableEsc'
    LIMIT 1
  ");
  return $q && $q->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
  $tableEsc = $conn->real_escape_string($table);
  $colEsc = $conn->real_escape_string($column);
  $dbRes = $conn->query("SELECT DATABASE() AS dbname");
  $db = $dbRes ? ($dbRes->fetch_assoc()['dbname'] ?? '') : '';
  $dbEsc = $conn->real_escape_string($db);
  $q = $conn->query("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = '$dbEsc' AND TABLE_NAME = '$tableEsc' AND COLUMN_NAME = '$colEsc'
    LIMIT 1
  ");
  return $q && $q->num_rows > 0;
}

function f($v): float { return (float)($v ?? 0); }

$hasApproved = columnExists($conn, "account_payable", "approved");
$hasApprovedBy = columnExists($conn, "account_payable", "approved_by");
$hasApprovedAt = columnExists($conn, "account_payable", "approved_at");

$hasSupplierPayments = tableExists($conn, "supplier_payments");

/* =========================
   Actions: Approve / Pay
========================= */
if($_SERVER['REQUEST_METHOD'] === 'POST'){

  // APPROVE (only if approved column exists)
  if(isset($_POST['approve_ap'])){
    if(!$hasApproved){
      $error = "Approval feature not available yet (missing 'approved' column in account_payable).";
    } else {
      $ap_id = (int)($_POST['ap_id'] ?? 0);
      if($ap_id <= 0){
        $error = "Invalid request (AP id).";
      } else {
        // Build update query depending on optional columns
        $sql = "UPDATE account_payable SET approved=1";
        if($hasApprovedBy) $sql .= ", approved_by=?";
        if($hasApprovedAt) $sql .= ", approved_at=NOW()";
        $sql .= " WHERE ap_id=?";

        $stmt = $conn->prepare($sql);
        if(!$stmt){
          $error = "DB error: ".$conn->error;
        } else {
          if($hasApprovedBy){
            $stmt->bind_param("ii", $owner_id, $ap_id);
          } else {
            $stmt->bind_param("i", $ap_id);
          }
          if($stmt->execute()){
            $success = "Payable approved.";
          } else {
            $error = "Failed to approve payable.";
          }
          $stmt->close();
        }
      }
    }
  }

  // PAY
  if(isset($_POST['pay_ap'])){
    $ap_id        = (int)($_POST['ap_id'] ?? 0);
    $amount       = (float)($_POST['amount'] ?? 0);
    $method       = strtolower(trim($_POST['method'] ?? 'cash'));
    $reference_no = trim($_POST['reference_no'] ?? '');
    $note         = trim($_POST['note'] ?? '');

    if($ap_id <= 0){
      $error = "Please select a payable.";
    } elseif($amount <= 0){
      $error = "Payment amount must be greater than 0.";
    } elseif(!in_array($method, ['cash','gcash','bank'])){
      $error = "Invalid payment method.";
    } else {
      // Fetch AP row (include approved if exists)
      $select = "SELECT ap_id, purchase_id, supplier_id, total_amount, amount_paid, balance, due_date, status";
      if($hasApproved) $select .= ", approved";
      $select .= " FROM account_payable WHERE ap_id=? LIMIT 1";

      $stmt = $conn->prepare($select);
      if(!$stmt){
        $error = "DB error: ".$conn->error;
      } else {
        $stmt->bind_param("i", $ap_id);
        $stmt->execute();
        $ap = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if(!$ap){
          $error = "Payable not found.";
        } else {
          if($hasApproved && (int)($ap['approved'] ?? 0) !== 1){
            $error = "This payable is not approved yet.";
          } else {
            $balance = f($ap['balance']);
            if($amount > $balance){
              $error = "Payment cannot exceed balance (₱".number_format($balance,2).").";
            } else {
              $conn->begin_transaction();
              try {
                // 1) Log supplier payment if table exists (recommended)
                if($hasSupplierPayments){
                  $purchase_id = (int)($ap['purchase_id'] ?? 0);
                  $supplier_id = (int)($ap['supplier_id'] ?? 0);

                  $stmtPay = $conn->prepare("
                    INSERT INTO supplier_payments
                      (ap_id, purchase_id, supplier_id, amount, method, reference_no, paid_at, paid_by, note, created_at)
                    VALUES
                      (?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW())
                  ");
                  if(!$stmtPay) throw new Exception("Prepare failed (supplier_payments): ".$conn->error);

                  $stmtPay->bind_param(
                    "iiidssis",
                    $ap_id, $purchase_id, $supplier_id, $amount, $method, $reference_no, $owner_id, $note
                  );
                  if(!$stmtPay->execute()) throw new Exception("Insert payment failed.");
                  $stmtPay->close();
                }

                // 2) Update AP amounts
                $new_paid    = f($ap['amount_paid']) + $amount;
                $new_balance = f($ap['balance']) - $amount;

                if($new_balance <= 0.00001){
                  $new_balance = 0;
                  $new_status = 'paid';
                } else {
                  $new_status = ($new_paid > 0) ? 'partial' : 'unpaid';
                }

                $stmtUp = $conn->prepare("
                  UPDATE account_payable
                  SET amount_paid=?, balance=?, status=?
                  WHERE ap_id=?
                ");
                if(!$stmtUp) throw new Exception("Prepare failed (account_payable): ".$conn->error);

                $stmtUp->bind_param("ddsi", $new_paid, $new_balance, $new_status, $ap_id);
                if(!$stmtUp->execute()) throw new Exception("Update payable failed.");
                $stmtUp->close();

                $conn->commit();
                $success = "Payment recorded. New balance: ₱".number_format($new_balance,2)
                         .(!$hasSupplierPayments ? " (Note: supplier_payments table not found — payment log skipped.)" : "");
              } catch(Exception $e){
                $conn->rollback();
                $error = "Failed to record payment: ".$e->getMessage();
              }
            }
          }
        }
      }
    }
  }
}

/* =========================
   Filters / Search / Paging
========================= */
$filter = strtolower(trim($_GET['filter'] ?? 'open'));
$allowedFilters = ['open','unapproved','overdue','paid','all'];
if(!in_array($filter, $allowedFilters)) $filter = 'open';

$search = trim($_GET['q'] ?? '');
$searchLike = '%'.$conn->real_escape_string($search).'%';

$page = (int)($_GET['page'] ?? 1);
if($page < 1) $page = 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// WHERE base
$where = "1=1";

// Filter logic
if($filter === 'open'){
  $where .= " AND ap.status IN ('unpaid','partial','overdue')";
} elseif($filter === 'unapproved'){
  if($hasApproved){
    $where .= " AND ap.approved = 0 AND ap.status IN ('unpaid','partial','overdue')";
  } else {
    // fallback: if no approval columns, show open instead
    $where .= " AND ap.status IN ('unpaid','partial','overdue')";
  }
} elseif($filter === 'overdue'){
  $where .= " AND (ap.status='overdue' OR (ap.due_date IS NOT NULL AND ap.due_date < CURDATE() AND ap.status <> 'paid'))";
} elseif($filter === 'paid'){
  $where .= " AND ap.status='paid'";
} elseif($filter === 'all'){
  $where .= " AND 1=1";
}

// Search
if($search !== ''){
  $where .= " AND (sup.name LIKE '$searchLike' OR ap.ap_id LIKE '$searchLike' OR ap.purchase_id LIKE '$searchLike')";
}

/* =========================
   Summary cards
========================= */
$sumSelect = "
  SELECT
    IFNULL(SUM(ap.total_amount),0) AS total_ap,
    IFNULL(SUM(ap.balance),0) AS balance_ap,
    SUM(CASE WHEN ap.status='unpaid' THEN 1 ELSE 0 END) AS cnt_unpaid,
    SUM(CASE WHEN ap.status='partial' THEN 1 ELSE 0 END) AS cnt_partial,
    SUM(CASE WHEN ap.status='paid' THEN 1 ELSE 0 END) AS cnt_paid,
    SUM(CASE WHEN ap.status='overdue' OR (ap.due_date IS NOT NULL AND ap.due_date < CURDATE() AND ap.status <> 'paid') THEN 1 ELSE 0 END) AS cnt_overdue
";

if($hasApproved){
  $sumSelect .= ", SUM(CASE WHEN ap.approved=0 AND ap.status <> 'paid' THEN 1 ELSE 0 END) AS cnt_unapproved";
} else {
  $sumSelect .= ", 0 AS cnt_unapproved";
}

$summary = $conn->query("
  $sumSelect
  FROM account_payable ap
  LEFT JOIN suppliers sup ON ap.supplier_id = sup.supplier_id
")->fetch_assoc() ?? [];

$total_ap = f($summary['total_ap']);
$bal_ap   = f($summary['balance_ap']);

/* =========================
   Payables list + total rows
========================= */
$listSelect = "
  SELECT
    ap.ap_id, ap.purchase_id, ap.supplier_id, ap.total_amount, ap.amount_paid, ap.balance, ap.due_date, ap.status, ap.created_at,
    sup.name AS supplier_name
";

if($hasApproved) $listSelect .= ", ap.approved";
if($hasApprovedBy) $listSelect .= ", ap.approved_by";
if($hasApprovedAt) $listSelect .= ", ap.approved_at";

$listSql = "
  $listSelect
  FROM account_payable ap
  LEFT JOIN suppliers sup ON ap.supplier_id = sup.supplier_id
  WHERE $where
  ORDER BY ap.created_at DESC
  LIMIT $perPage OFFSET $offset
";

$payables = $conn->query($listSql);

// Count for pagination
$countRes = $conn->query("
  SELECT COUNT(*) AS cnt
  FROM account_payable ap
  LEFT JOIN suppliers sup ON ap.supplier_id = sup.supplier_id
  WHERE $where
")->fetch_assoc();
$totalRows = (int)($countRes['cnt'] ?? 0);
$totalPages = (int)ceil($totalRows / $perPage);

// Optional: View AP payment history
$view_ap_id = (int)($_GET['view'] ?? 0);
$viewAP = null;
$viewPayments = null;

if($view_ap_id > 0){
  $sel = "SELECT ap.*, sup.name AS supplier_name FROM account_payable ap
          LEFT JOIN suppliers sup ON ap.supplier_id=sup.supplier_id
          WHERE ap.ap_id=? LIMIT 1";
  $stmtV = $conn->prepare($sel);
  if($stmtV){
    $stmtV->bind_param("i", $view_ap_id);
    $stmtV->execute();
    $viewAP = $stmtV->get_result()->fetch_assoc();
    $stmtV->close();
  }
  if($hasSupplierPayments && $viewAP){
    $stmtP = $conn->prepare("
      SELECT sp.*
      FROM supplier_payments sp
      WHERE sp.ap_id=?
      ORDER BY sp.paid_at DESC
      LIMIT 50
    ");
    if($stmtP){
      $stmtP->bind_param("i", $view_ap_id);
      $stmtP->execute();
      $viewPayments = $stmtP->get_result();
      $stmtP->close();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Supplier Payables | Owner</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }
.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); }
.table td, .table th { vertical-align: middle; }
.small-muted { font-size:.9rem; color:#6c757d; }
.main-content { padding-top:70px; padding-left: 20px; padding-right: 20px; }

.badge-unapproved { background:#6c757d; }
.badge-approved { background:#0d6efd; }

.badge-paid { background:#198754; }
.badge-partial { background:#fd7e14; }
.badge-unpaid { background:#dc3545; }
.badge-overdue { background:#b02a37; }

.card-kpi h6{ margin:0; opacity:.9; font-weight:600; }
.card-kpi h3{ margin:0; font-weight:800; }

.kpi-sub { font-size:.85rem; opacity:.85; }

.nav-pills .nav-link{ border-radius:999px; }
</style>
</head>
<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<!-- Top Navbar -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top" style="margin-left: 260px; width: calc(100% - 260px); z-index: 1020;">
  <div class="container-fluid">
    <span class="navbar-brand fw-bold ms-2">DE ORO HIYS GENERAL MERCHANDISE</span>

    <div class="ms-auto dropdown">
      <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#">
        <?= htmlspecialchars($username) ?> <small class="text-muted">(Owner)</small>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="../owner/dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i>Owner Dashboard</a></li>
        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<main class="main-content">
<div class="container-fluid">

  <?php if($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>
  <?php if($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- KPI Cards -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card card-kpi p-3">
        <h6>Total AP</h6>
        <h3>₱<?= number_format($total_ap,2) ?></h3>
        <div class="kpi-sub text-muted">All supplier purchases</div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card card-kpi p-3">
        <h6>Outstanding Balance</h6>
        <h3>₱<?= number_format($bal_ap,2) ?></h3>
        <div class="kpi-sub text-muted">Unpaid + partial</div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card card-kpi p-3">
        <h6>Overdue</h6>
        <h3><?= (int)($summary['cnt_overdue'] ?? 0) ?></h3>
        <div class="kpi-sub text-muted">Needs immediate action</div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card card-kpi p-3">
        <h6>Unapproved</h6>
        <h3><?= (int)($summary['cnt_unapproved'] ?? 0) ?></h3>
        <div class="kpi-sub text-muted"><?= $hasApproved ? "Pending approval" : "Approval not enabled" ?></div>
      </div>
    </div>
  </div>

  <!-- Filters + Search -->
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-0">Accounts Payable List</h4>
      <div class="small-muted">
        Workflow: <b>Approve</b> (optional) → <b>Record Payment</b> → Status updates automatically.
      </div>
    </div>

    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <div class="input-group">
        <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Search supplier / AP # / Purchase #"
               value="<?= htmlspecialchars($search) ?>">
      </div>
      <button class="btn btn-dark" type="submit"><i class="fa-solid fa-search me-1"></i>Search</button>
      <a class="btn btn-outline-secondary" href="supplier_payables.php"><i class="fa-solid fa-rotate-left me-1"></i>Reset</a>
    </form>
  </div>

  <!-- Status pills -->
  <div class="mb-3">
    <div class="nav nav-pills gap-2">
      <a class="nav-link <?= $filter==='open'?'active':'' ?>" href="?filter=open&q=<?= urlencode($search) ?>">Open</a>
      <a class="nav-link <?= $filter==='unapproved'?'active':'' ?>" href="?filter=unapproved&q=<?= urlencode($search) ?>">Unapproved</a>
      <a class="nav-link <?= $filter==='overdue'?'active':'' ?>" href="?filter=overdue&q=<?= urlencode($search) ?>">Overdue</a>
      <a class="nav-link <?= $filter==='paid'?'active':'' ?>" href="?filter=paid&q=<?= urlencode($search) ?>">Paid</a>
      <a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all&q=<?= urlencode($search) ?>">All</a>
    </div>
  </div>

  <!-- Table -->
  <div class="card modern-card">
    <div class="card-body table-responsive">
      <table class="table table-striped table-bordered mb-0">
        <thead class="table-dark">
          <tr>
            <th style="width:70px;">AP #</th>
            <th>Supplier</th>
            <th style="width:100px;">Purchase #</th>
            <th style="width:130px;">Total</th>
            <th style="width:130px;">Paid</th>
            <th style="width:130px;">Balance</th>
            <th style="width:120px;">Due</th>
            <th style="width:120px;">Status</th>
            <th style="width:130px;">Approval</th>
            <th style="min-width:280px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if($payables && $payables->num_rows > 0): ?>
            <?php while($row = $payables->fetch_assoc()): ?>
              <?php
                $ap_id = (int)$row['ap_id'];
                $supplierName = $row['supplier_name'] ?? 'N/A';

                $status = strtolower($row['status'] ?? 'unpaid');
                $balance = f($row['balance']);
                $due = $row['due_date'] ?? '';

                // auto-overdue badge if due_date passed and not paid
                $isDuePast = false;
                if($due && $status !== 'paid'){
                  $isDuePast = (strtotime($due) < strtotime(date('Y-m-d')));
                }

                $statusBadge = 'badge-unpaid';
                if($status === 'paid') $statusBadge = 'badge-paid';
                else if($status === 'partial') $statusBadge = 'badge-partial';
                else if($status === 'overdue' || $isDuePast) $statusBadge = 'badge-overdue';

                $approved = $hasApproved ? ((int)($row['approved'] ?? 0) === 1) : true;
                $approvalBadge = $approved ? "badge-approved" : "badge-unapproved";
                $approvalText  = $approved ? "APPROVED" : "UNAPPROVED";
              ?>
              <tr>
                <td class="fw-bold"><?= $ap_id ?></td>
                <td><?= htmlspecialchars($supplierName) ?></td>
                <td><?= (int)($row['purchase_id'] ?? 0) ?></td>
                <td>₱<?= number_format(f($row['total_amount']),2) ?></td>
                <td>₱<?= number_format(f($row['amount_paid']),2) ?></td>
                <td class="fw-bold">₱<?= number_format($balance,2) ?></td>
                <td><?= htmlspecialchars($due) ?></td>
                <td><span class="badge <?= $statusBadge ?>"><?= strtoupper($isDuePast && $status!=='paid' ? 'overdue' : $status) ?></span></td>
                <td>
                  <span class="badge <?= $approvalBadge ?>"><?= $hasApproved ? $approvalText : "N/A" ?></span>
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-2">

                    <!-- View history -->
                    <a class="btn btn-sm btn-outline-dark"
                       href="?filter=<?= urlencode($filter) ?>&q=<?= urlencode($search) ?>&page=<?= (int)$page ?>&view=<?= $ap_id ?>">
                      <i class="fa-solid fa-clock-rotate-left me-1"></i>History
                    </a>

                    <?php if($hasApproved && !$approved && $status !== 'paid'): ?>
                      <!-- Approve -->
                      <form method="POST" class="m-0">
                        <input type="hidden" name="ap_id" value="<?= $ap_id ?>">
                        <button class="btn btn-sm btn-success" type="submit" name="approve_ap">
                          <i class="fa-solid fa-check me-1"></i>Approve
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if($approved && $status !== 'paid' && $balance > 0): ?>
                      <!-- Pay -->
                      <button class="btn btn-sm btn-primary" type="button"
                              data-bs-toggle="collapse" data-bs-target="#payForm<?= $ap_id ?>">
                        <i class="fa-solid fa-money-bill-wave me-1"></i>Pay
                      </button>
                    <?php endif; ?>

                  </div>

                  <?php if($approved && $status !== 'paid' && $balance > 0): ?>
                    <div class="collapse mt-2" id="payForm<?= $ap_id ?>">
                      <div class="border rounded p-2 bg-light">
                        <form method="POST" class="row g-2 m-0">
                          <input type="hidden" name="ap_id" value="<?= $ap_id ?>">

                          <div class="col-12 col-md-4">
                            <label class="form-label small mb-1">Amount</label>
                            <input type="number" step="0.01" min="0.01"
                                   max="<?= htmlspecialchars((string)$balance) ?>"
                                   name="amount" class="form-control form-control-sm" required>
                            <div class="small text-muted">Max: ₱<?= number_format($balance,2) ?></div>
                          </div>

                          <div class="col-12 col-md-4">
                            <label class="form-label small mb-1">Method</label>
                            <select name="method" class="form-select form-select-sm">
                              <option value="cash">Cash</option>
                              <option value="gcash">GCash</option>
                              <option value="bank">Bank</option>
                            </select>
                          </div>

                          <div class="col-12 col-md-4">
                            <label class="form-label small mb-1">Reference # (optional)</label>
                            <input type="text" name="reference_no" class="form-control form-control-sm" placeholder="e.g. GCash Ref">
                          </div>

                          <div class="col-12">
                            <label class="form-label small mb-1">Note (optional)</label>
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="Payment remark">
                          </div>

                          <div class="col-12 d-flex gap-2">
                            <button class="btn btn-sm btn-primary" type="submit" name="pay_ap">
                              <i class="fa-solid fa-floppy-disk me-1"></i>Record Payment
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#payForm<?= $ap_id ?>">
                              Close
                            </button>
                          </div>
                        </form>
                      </div>
                    </div>
                  <?php endif; ?>

                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="10" class="text-center text-muted">No payables found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="small text-muted">
        Showing <?= min($totalRows, $offset + 1) ?>–<?= min($totalRows, $offset + $perPage) ?> of <?= $totalRows ?> results
      </div>

      <nav>
        <ul class="pagination pagination-sm mb-0">
          <?php
            $base = "supplier_payables.php?filter=".urlencode($filter)."&q=".urlencode($search);
          ?>
          <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= $base ?>&page=<?= max(1,$page-1) ?>">Prev</a>
          </li>

          <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            for($i=$start; $i<=$end; $i++):
          ?>
            <li class="page-item <?= $i===$page?'active':'' ?>">
              <a class="page-link" href="<?= $base ?>&page=<?= $i ?>"><?= $i ?></a>
            </li>
          <?php endfor; ?>

          <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
            <a class="page-link" href="<?= $base ?>&page=<?= min($totalPages,$page+1) ?>">Next</a>
          </li>
        </ul>
      </nav>
    </div>
  </div>

</div>
</main>

<!-- History Modal (server-rendered) -->
<?php if($viewAP): ?>
<div class="modal fade show" id="historyModal" tabindex="-1" style="display:block;" aria-modal="true" role="dialog">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">
          Payment History • AP #<?= (int)$viewAP['ap_id'] ?> • <?= htmlspecialchars($viewAP['supplier_name'] ?? 'N/A') ?>
        </h5>
        <a class="btn-close" href="<?= htmlspecialchars("supplier_payables.php?filter=$filter&q=".urlencode($search)."&page=$page") ?>"></a>
      </div>

      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="border rounded p-2 bg-light">
              <div class="small text-muted">Total</div>
              <div class="fw-bold">₱<?= number_format(f($viewAP['total_amount']),2) ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="border rounded p-2 bg-light">
              <div class="small text-muted">Paid</div>
              <div class="fw-bold">₱<?= number_format(f($viewAP['amount_paid']),2) ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="border rounded p-2 bg-light">
              <div class="small text-muted">Balance</div>
              <div class="fw-bold">₱<?= number_format(f($viewAP['balance']),2) ?></div>
            </div>
          </div>
        </div>

        <?php if(!$hasSupplierPayments): ?>
          <div class="alert alert-warning mb-0">
            supplier_payments table not found, so payment history cannot be displayed.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-striped table-bordered">
              <thead class="table-dark">
                <tr>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Reference</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
                <?php if($viewPayments && $viewPayments->num_rows > 0): ?>
                  <?php while($p = $viewPayments->fetch_assoc()): ?>
                    <tr>
                      <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($p['paid_at']))) ?></td>
                      <td class="fw-bold">₱<?= number_format(f($p['amount']),2) ?></td>
                      <td><?= htmlspecialchars(strtoupper($p['method'] ?? '')) ?></td>
                      <td><?= htmlspecialchars($p['reference_no'] ?? '') ?></td>
                      <td><?= htmlspecialchars($p['note'] ?? '') ?></td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr><td colspan="5" class="text-center text-muted">No payments recorded yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="modal-footer">
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars("supplier_payables.php?filter=$filter&q=".urlencode($search)."&page=$page") ?>">Close</a>
      </div>
    </div>
  </div>
</div>
<div class="modal-backdrop fade show"></div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
