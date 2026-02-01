<?php
session_start();
if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'owner'){ header("Location: ../login.php"); exit; }

$username = $_SESSION['username'] ?? 'Owner';
include '../config/db.php';

$days = (int)($_GET['days'] ?? 30);
if(!in_array($days, [7,30,90])) $days = 30;

/* =========================
   Helpers (safe checks)
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

$hasSupplierPayments = tableExists($conn, "supplier_payments");

/* =========================
   Activity Logs
========================= */
$activity = $conn->query("
  SELECT al.activity_id, al.created_at, al.activity_type, al.description,
         CONCAT(u.first_name,' ',u.last_name) AS user_name, u.role
  FROM activity_logs al
  LEFT JOIN users u ON u.user_id = al.user_id
  WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  ORDER BY al.created_at DESC
  LIMIT 200
");

/* =========================
   Login Logs
========================= */
$login = $conn->query("
  SELECT ll.log_id, ll.login_time, ll.device_info, ll.ip_address,
         CONCAT(u.first_name,' ',u.last_name) AS user_name, u.role
  FROM login_logs ll
  LEFT JOIN users u ON u.user_id = ll.user_id
  WHERE ll.login_time >= DATE_SUB(NOW(), INTERVAL $days DAY)
  ORDER BY ll.login_time DESC
  LIMIT 200
");

/* =========================
   Payment / Notification Logs
========================= */
$payments = $conn->query("
  SELECT p.payment_id, p.sale_id, p.amount, p.method, p.status, p.paid_at, p.external_ref
  FROM payments p
  WHERE p.paid_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  ORDER BY p.paid_at DESC
  LIMIT 200
");

$payreq = $conn->query("
  SELECT pr.pay_req_id, pr.sale_id, pr.phone, pr.requested_at, pr.status
  FROM payment_request pr
  WHERE pr.requested_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  ORDER BY pr.requested_at DESC
  LIMIT 200
");

$push = $conn->query("
  SELECT pn.push_notif_id, pn.payment_id, pn.customer_id, pn.message, pn.sent_at, pn.status
  FROM push_notif_logs pn
  WHERE pn.sent_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  ORDER BY pn.sent_at DESC
  LIMIT 200
");

/* =========================
   FINANCE LOGS (AP / AR)
   Works even without activity_logs logging
========================= */

// AR list (Receivable)
$ar = $conn->query("
  SELECT ar.ar_id, ar.sales_id, ar.customer_id, ar.total_amount, ar.amount_paid, ar.balance,
         ar.due_date, ar.status, ar.created_at,
         CONCAT(c.first_name,' ',c.last_name) AS customer_name
  FROM account_receivable ar
  LEFT JOIN customers c ON c.customer_id = ar.customer_id
  WHERE ar.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  ORDER BY ar.created_at DESC
  LIMIT 200
");

// AP list (Payable)
$ap = $conn->query("
  SELECT ap.ap_id, ap.purchase_id, ap.supplier_id, ap.total_amount, ap.amount_paid, ap.balance,
         ap.due_date, ap.status, ap.created_at,
         s.name AS supplier_name
  FROM account_payable ap
  LEFT JOIN suppliers s ON s.supplier_id = ap.supplier_id
  WHERE ap.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
  ORDER BY ap.created_at DESC
  LIMIT 200
");

// Supplier payment history (optional)
$supplierPayments = null;
if($hasSupplierPayments){
  $supplierPayments = $conn->query("
    SELECT sp.*, sup.name AS supplier_name
    FROM supplier_payments sp
    LEFT JOIN suppliers sup ON sup.supplier_id = sp.supplier_id
    WHERE sp.paid_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
    ORDER BY sp.paid_at DESC
    LIMIT 200
  ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>System Logs | Owner</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }

.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); }
.main-content { padding-top:70px; padding-left: 20px; padding-right: 20px; }

.table td, .table th { padding:0.55rem; vertical-align: middle; }
@media print { .sidebar, .navbar, .no-print { display:none !important; } body{padding-top:0} }
</style>
</head>
<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<!-- TOP NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top no-print" style="margin-left: 260px; width: calc(100% - 260px); z-index: 1020;">
  <div class="container-fluid">
    <span class="navbar-brand fw-bold ms-2">DE ORO HIYS GENERAL MERCHANDISE</span>

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
      <h3 class="fw-bold mb-1">System Logs</h3>
      <div class="text-muted">Track activity and access history (read-only).</div>
    </div>

    <div class="d-flex gap-2 align-items-center no-print">
      <form method="get" class="d-flex gap-2 align-items-center">
        <select class="form-select" name="days" onchange="this.form.submit()">
          <option value="7"  <?= $days===7?'selected':'' ?>>Last 7 days</option>
          <option value="30" <?= $days===30?'selected':'' ?>>Last 30 days</option>
          <option value="90" <?= $days===90?'selected':'' ?>>Last 90 days</option>
        </select>
      </form>
      <button class="btn btn-outline-dark" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>
    </div>
  </div>

  <div class="card modern-card">
    <div class="card-body">
      <ul class="nav nav-pills mb-3" id="logsTab" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-activity" type="button">
            <i class="fa-solid fa-clipboard-list me-1"></i> Activity Logs
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-login" type="button">
            <i class="fa-solid fa-right-to-bracket me-1"></i> Login Logs
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-pay" type="button">
            <i class="fa-solid fa-bell me-1"></i> Payment / Notification Logs
          </button>
        </li>

        <!-- NEW TAB -->
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-finance" type="button">
            <i class="fa-solid fa-coins me-1"></i> Finance Logs (AR/AP)
          </button>
        </li>
      </ul>

      <div class="tab-content">

        <!-- Activity Logs -->
        <div class="tab-pane fade show active" id="tab-activity">
          <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Date</th>
                  <th>User</th>
                  <th>Role</th>
                  <th>Type</th>
                  <th>Description</th>
                </tr>
              </thead>
              <tbody>
              <?php if($activity && $activity->num_rows>0): ?>
                <?php while($r=$activity->fetch_assoc()): ?>
                  <tr>
                    <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($r['created_at']))) ?></td>
                    <td><?= htmlspecialchars($r['user_name'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($r['role'] ?: '—') ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($r['activity_type'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($r['description'] ?: '') ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="5" class="text-center text-muted">No activity logs found.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Login Logs -->
        <div class="tab-pane fade" id="tab-login">
          <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Login Time</th>
                  <th>User</th>
                  <th>Role</th>
                  <th>Device</th>
                  <th>IP Address</th>
                </tr>
              </thead>
              <tbody>
              <?php if($login && $login->num_rows>0): ?>
                <?php while($r=$login->fetch_assoc()): ?>
                  <tr>
                    <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($r['login_time']))) ?></td>
                    <td><?= htmlspecialchars($r['user_name'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($r['role'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($r['device_info'] ?: '—') ?></td>
                    <td><?= htmlspecialchars($r['ip_address'] ?: '—') ?></td>
                  </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr><td colspan="5" class="text-center text-muted">No login logs found.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Payment / Notification Logs -->
        <div class="tab-pane fade" id="tab-pay">
          <div class="row g-3">

            <div class="col-12">
              <h6 class="fw-bold mb-2"><i class="fa-solid fa-credit-card me-1"></i> Payments</h6>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Paid At</th>
                      <th>Payment ID</th>
                      <th>Sale ID</th>
                      <th class="text-end">Amount</th>
                      <th>Method</th>
                      <th>Status</th>
                      <th>External Ref</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if($payments && $payments->num_rows>0): ?>
                    <?php while($r=$payments->fetch_assoc()): ?>
                      <?php
                        $st = strtolower(trim($r['status'] ?? ''));
                        $badge = 'bg-secondary';
                        if($st==='paid' || $st==='success') $badge='bg-success';
                        elseif($st==='pending') $badge='bg-warning text-dark';
                        elseif($st==='failed') $badge='bg-danger';
                      ?>
                      <tr>
                        <td><?= $r['paid_at'] ? htmlspecialchars(date("M d, Y h:i A", strtotime($r['paid_at']))) : '—' ?></td>
                        <td class="fw-semibold">#<?= (int)$r['payment_id'] ?></td>
                        <td>#<?= (int)$r['sale_id'] ?></td>
                        <td class="text-end fw-bold">₱<?= number_format((float)$r['amount'],2) ?></td>
                        <td><?= htmlspecialchars($r['method'] ?: '—') ?></td>
                        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(strtoupper($st ?: 'N/A')) ?></span></td>
                        <td><?= htmlspecialchars($r['external_ref'] ?: '—') ?></td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr><td colspan="7" class="text-center text-muted">No payment logs found.</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="col-12 col-xl-6">
              <h6 class="fw-bold mb-2 mt-3"><i class="fa-solid fa-paper-plane me-1"></i> Payment Requests</h6>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Requested At</th>
                      <th>Request ID</th>
                      <th>Sale ID</th>
                      <th>Phone</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if($payreq && $payreq->num_rows>0): ?>
                    <?php while($r=$payreq->fetch_assoc()): ?>
                      <?php
                        $st = strtolower(trim($r['status'] ?? ''));
                        $badge = 'bg-secondary';
                        if($st==='sent') $badge='bg-info';
                        elseif($st==='pending') $badge='bg-warning text-dark';
                        elseif($st==='done' || $st==='completed') $badge='bg-success';
                        elseif($st==='failed') $badge='bg-danger';
                      ?>
                      <tr>
                        <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($r['requested_at']))) ?></td>
                        <td class="fw-semibold">#<?= (int)$r['pay_req_id'] ?></td>
                        <td>#<?= (int)$r['sale_id'] ?></td>
                        <td><?= htmlspecialchars($r['phone'] ?: '—') ?></td>
                        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(strtoupper($st ?: 'N/A')) ?></span></td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr><td colspan="5" class="text-center text-muted">No payment request logs found.</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="col-12 col-xl-6">
              <h6 class="fw-bold mb-2 mt-3"><i class="fa-solid fa-bell me-1"></i> Push Notifications</h6>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Sent At</th>
                      <th>Notif ID</th>
                      <th>Payment ID</th>
                      <th>Customer ID</th>
                      <th>Status</th>
                      <th>Message</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if($push && $push->num_rows>0): ?>
                    <?php while($r=$push->fetch_assoc()): ?>
                      <?php
                        $st = strtolower(trim($r['status'] ?? ''));
                        $badge = 'bg-secondary';
                        if($st==='sent' || $st==='success') $badge='bg-success';
                        elseif($st==='pending') $badge='bg-warning text-dark';
                        elseif($st==='failed') $badge='bg-danger';
                      ?>
                      <tr>
                        <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($r['sent_at']))) ?></td>
                        <td class="fw-semibold">#<?= (int)$r['push_notif_id'] ?></td>
                        <td><?= $r['payment_id'] !== null ? '#'.(int)$r['payment_id'] : '—' ?></td>
                        <td><?= $r['customer_id'] !== null ? (int)$r['customer_id'] : '—' ?></td>
                        <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(strtoupper($st ?: 'N/A')) ?></span></td>
                        <td><?= htmlspecialchars($r['message'] ?: '') ?></td>
                      </tr>
                    <?php endwhile; ?>
                  <?php else: ?>
                    <tr><td colspan="6" class="text-center text-muted">No push notification logs found.</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

          </div>
        </div>

        <!-- ✅ NEW: FINANCE LOGS -->
        <div class="tab-pane fade" id="tab-finance">
          <div class="row g-4">

            <!-- AR -->
            <div class="col-12">
              <h6 class="fw-bold mb-2"><i class="fa-solid fa-hand-holding-dollar me-1"></i> Accounts Receivable (AR)</h6>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Date</th>
                      <th>AR #</th>
                      <th>Sale #</th>
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
                          $st = strtolower(trim($r['status'] ?? ''));
                          $badge = 'bg-secondary';
                          if($st==='paid') $badge='bg-success';
                          elseif($st==='partial') $badge='bg-warning text-dark';
                          elseif($st==='unpaid' || $st==='overdue') $badge='bg-danger';
                        ?>
                        <tr>
                          <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($r['created_at']))) ?></td>
                          <td class="fw-semibold">#<?= (int)$r['ar_id'] ?></td>
                          <td>#<?= (int)$r['sales_id'] ?></td>
                          <td><?= htmlspecialchars($r['customer_name'] ?: '—') ?></td>
                          <td class="text-end">₱<?= number_format((float)$r['total_amount'],2) ?></td>
                          <td class="text-end">₱<?= number_format((float)$r['amount_paid'],2) ?></td>
                          <td class="text-end fw-bold">₱<?= number_format((float)$r['balance'],2) ?></td>
                          <td><?= htmlspecialchars($r['due_date'] ?: '—') ?></td>
                          <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(strtoupper($st ?: 'N/A')) ?></span></td>
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
            <div class="col-12">
              <h6 class="fw-bold mb-2 mt-2"><i class="fa-solid fa-file-invoice-dollar me-1"></i> Accounts Payable (AP)</h6>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Date</th>
                      <th>AP #</th>
                      <th>Purchase #</th>
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
                          $st = strtolower(trim($r['status'] ?? ''));
                          $badge = 'bg-secondary';
                          if($st==='paid') $badge='bg-success';
                          elseif($st==='partial') $badge='bg-warning text-dark';
                          elseif($st==='unpaid' || $st==='overdue') $badge='bg-danger';
                        ?>
                        <tr>
                          <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($r['created_at']))) ?></td>
                          <td class="fw-semibold">#<?= (int)$r['ap_id'] ?></td>
                          <td><?= $r['purchase_id'] !== null ? '#'.(int)$r['purchase_id'] : '—' ?></td>
                          <td><?= htmlspecialchars($r['supplier_name'] ?: '—') ?></td>
                          <td class="text-end">₱<?= number_format((float)$r['total_amount'],2) ?></td>
                          <td class="text-end">₱<?= number_format((float)$r['amount_paid'],2) ?></td>
                          <td class="text-end fw-bold">₱<?= number_format((float)$r['balance'],2) ?></td>
                          <td><?= htmlspecialchars($r['due_date'] ?: '—') ?></td>
                          <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(strtoupper($st ?: 'N/A')) ?></span></td>
                        </tr>
                      <?php endwhile; ?>
                    <?php else: ?>
                      <tr><td colspan="9" class="text-center text-muted">No AP records found.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Supplier Payments (optional table) -->
            <div class="col-12">
              <h6 class="fw-bold mb-2 mt-2">
                <i class="fa-solid fa-money-check-dollar me-1"></i> Supplier Payment Records
                <span class="badge bg-secondary"><?= $hasSupplierPayments ? "Enabled" : "Not found" ?></span>
              </h6>

              <?php if(!$hasSupplierPayments): ?>
                <div class="alert alert-warning mb-0">
                  supplier_payments table not found. AP payments can still work, but payment history won’t show here.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-striped align-middle mb-0">
                    <thead class="table-dark">
                      <tr>
                        <th>Paid At</th>
                        <th>AP #</th>
                        <th>Purchase #</th>
                        <th>Supplier</th>
                        <th class="text-end">Amount</th>
                        <th>Method</th>
                        <th>Reference #</th>
                        <th>Note</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if($supplierPayments && $supplierPayments->num_rows>0): ?>
                        <?php while($r=$supplierPayments->fetch_assoc()): ?>
                          <tr>
                            <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($r['paid_at']))) ?></td>
                            <td class="fw-semibold">#<?= (int)$r['ap_id'] ?></td>
                            <td><?= $r['purchase_id'] !== null ? '#'.(int)$r['purchase_id'] : '—' ?></td>
                            <td><?= htmlspecialchars($r['supplier_name'] ?: '—') ?></td>
                            <td class="text-end fw-bold">₱<?= number_format((float)$r['amount'],2) ?></td>
                            <td><?= htmlspecialchars(strtoupper($r['method'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($r['reference_no'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($r['note'] ?? '') ?></td>
                          </tr>
                        <?php endwhile; ?>
                      <?php else: ?>
                        <tr><td colspan="8" class="text-center text-muted">No supplier payments found.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>

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
