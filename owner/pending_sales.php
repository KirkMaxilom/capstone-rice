<?php
session_start();
if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'owner'){ header("Location: ../login.php"); exit; }

$username = $_SESSION['username'] ?? 'Owner';
$owner_id = (int)$_SESSION['user_id'];
include '../config/db.php';

function log_activity($conn, $user_id, $type, $desc){
  $stmt = $conn->prepare("INSERT INTO activity_logs(user_id, activity_type, description, created_at) VALUES(?,?,?,NOW())");
  $stmt->bind_param("iss", $user_id, $type, $desc);
  $stmt->execute();
  $stmt->close();
}

$msg = "";

// APPROVE
if(isset($_POST['approve_sale'])){
  $sale_id = (int)($_POST['sale_id'] ?? 0);
  if($sale_id > 0){
    $conn->query("UPDATE sales SET status='approved' WHERE sale_id=$sale_id AND LOWER(status) IN ('paid','partial')");
    log_activity($conn, $owner_id, "SALE_APPROVED", "Approved sale #$sale_id (post-payment audit)");
    $msg = "Sale #$sale_id approved.";
  }
}

// REJECT (void)
if(isset($_POST['reject_sale'])){
  $sale_id = (int)($_POST['sale_id'] ?? 0);
  if($sale_id > 0){
    $conn->query("UPDATE sales SET status='cancelled' WHERE sale_id=$sale_id AND LOWER(status) IN ('paid','partial')");
    // optional: also cancel AR if exists
    $conn->query("UPDATE account_receivable SET status='cancelled' WHERE sales_id=$sale_id");

    log_activity($conn, $owner_id, "SALE_REJECTED", "Rejected sale #$sale_id (after payment) - manual refund may be required");
    $msg = "Sale #$sale_id rejected. (If payment already collected, refund may be required.)";
  }
}

// Fetch sales that are PAID or PARTIAL but not yet approved
$sales = $conn->query("
  SELECT
    s.sale_id, s.sale_date, s.total_amount, s.status,
    CONCAT(c.first_name,' ',c.last_name) AS customer_name,
    CONCAT(u.first_name,' ',u.last_name) AS cashier_name,
    COALESCE(SUM(si.qty_kg),0) AS total_kg
  FROM sales s
  LEFT JOIN customers c ON c.customer_id = s.customer_id
  LEFT JOIN users u ON u.user_id = s.user_id
  LEFT JOIN sales_items si ON si.sale_id = s.sale_id
  WHERE LOWER(s.status) IN ('paid','partial')
  GROUP BY s.sale_id
  ORDER BY s.sale_date DESC
");

function sale_items($conn, $sale_id){
  return $conn->query("
    SELECT si.qty_kg, si.unit_price, si.line_total, p.variety, p.grade
    FROM sales_items si
    LEFT JOIN products p ON p.product_id = si.product_id
    WHERE si.sale_id = $sale_id
  ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sales for Approval | Owner</title>

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

<main class="main-content">
<div class="container-fluid">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h3 class="fw-bold mb-1">Sales for Approval</h3>
      <div class="text-muted">Paid/Partial sales awaiting owner review.</div>
    </div>
  </div>

  <?php if($msg): ?>
    <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <div class="card modern-card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>Date</th>
              <th>Sale</th>
              <th>Cashier</th>
              <th>Customer</th>
              <th class="text-end">Kg</th>
              <th class="text-end">Total</th>
              <th>Status</th>
              <th style="min-width:220px;" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if($sales && $sales->num_rows>0): ?>
            <?php while($s=$sales->fetch_assoc()): ?>
              <?php $sid=(int)$s['sale_id']; $st=strtolower(trim($s['status'])); ?>
              <tr>
                <td><?= htmlspecialchars(date("M d, Y h:i A", strtotime($s['sale_date']))) ?></td>
                <td class="fw-bold">#<?= $sid ?></td>
                <td><?= htmlspecialchars($s['cashier_name'] ?: '—') ?></td>
                <td><?= htmlspecialchars($s['customer_name'] ?: 'Walk-in') ?></td>
                <td class="text-end fw-bold"><?= number_format((float)$s['total_kg'],2) ?></td>
                <td class="text-end">₱<?= number_format((float)$s['total_amount'],2) ?></td>
                <td>
                  <span class="badge <?= $st==='paid'?'bg-success':'bg-info' ?>">
                    <?= strtoupper($st) ?>
                  </span>
                </td>
                <td class="text-end">
                  <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#details<?= $sid ?>">
                    <i class="fa-solid fa-eye me-1"></i> Details
                  </button>

                  <form method="post" class="d-inline">
                    <input type="hidden" name="sale_id" value="<?= $sid ?>">
                    <button class="btn btn-sm btn-success" name="approve_sale">
                      <i class="fa-solid fa-check me-1"></i> Approve
                    </button>
                  </form>

                  <form method="post" class="d-inline" onsubmit="return confirm('Reject sale #<?= $sid ?>? This will CANCEL it.');">
                    <input type="hidden" name="sale_id" value="<?= $sid ?>">
                    <button class="btn btn-sm btn-danger" name="reject_sale">
                      <i class="fa-solid fa-xmark me-1"></i> Reject
                    </button>
                  </form>
                </td>
              </tr>

              <tr class="collapse" id="details<?= $sid ?>">
                <td colspan="8">
                  <div class="p-2">
                    <div class="fw-bold mb-2">Items for Sale #<?= $sid ?></div>
                    <div class="table-responsive">
                      <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                          <tr>
                            <th>Product</th>
                            <th class="text-end">Qty (kg)</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Line Total</th>
                          </tr>
                        </thead>
                        <tbody>
                        <?php $it=sale_items($conn,$sid); if($it && $it->num_rows>0): while($r=$it->fetch_assoc()): ?>
                          <tr>
                            <td><?= htmlspecialchars(trim(($r['variety'] ?? 'N/A')." - ".($r['grade'] ?? ''))) ?></td>
                            <td class="text-end fw-bold"><?= number_format((float)$r['qty_kg'],2) ?></td>
                            <td class="text-end">₱<?= number_format((float)$r['unit_price'],2) ?></td>
                            <td class="text-end">₱<?= number_format((float)$r['line_total'],2) ?></td>
                          </tr>
                        <?php endwhile; else: ?>
                          <tr><td colspan="4" class="text-center text-muted">No items found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </td>
              </tr>

            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="8" class="text-center text-muted">No paid/partial sales awaiting approval.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="text-muted small mt-2">
        <i class="fa-solid fa-circle-info me-1"></i>
        Approve confirms the transaction is valid for audit. Reject cancels it (refund may be required if payment was collected).
      </div>
    </div>
  </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
