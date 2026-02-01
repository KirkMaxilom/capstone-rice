<?php
session_start();
if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'owner'){ header("Location: ../login.php"); exit; }

$username = $_SESSION['username'] ?? 'Owner';
include '../config/db.php';

// HANDLE APPROVE / REJECT (OWNER)
if(isset($_POST['return_id'], $_POST['action'])){
$return_id = (int)$_POST['return_id'];
$action = strtolower($_POST['action']);

// Get return info
$stmt = $conn->prepare("
SELECT product_id, qty_returned, status
FROM returns
WHERE return_id = ?
");
$stmt->bind_param("i", $return_id);
$stmt->execute();
$ret = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Only allow pending
if($ret && strtolower($ret['status']) === 'pending'){

if($action === 'approve'){
// Update return status
$stmt = $conn->prepare("UPDATE returns SET status='APPROVED' WHERE return_id=?");
$stmt->bind_param("i", $return_id);
$stmt->execute();
$stmt->close();

// Add stock back
$note = "Approved return #{$return_id}";
$stmt = $conn->prepare("
INSERT INTO inventory_transactions
(product_id, qty_kg, reference_id, reference_type, type, note, created_at)
VALUES (?, ?, ?, 'return', 'in', ?, NOW())
");
$stmt->bind_param(
"idis",
$ret['product_id'],
$ret['qty_returned'],
$return_id,
$note
);
$stmt->execute();
$stmt->close();
}

if($action === 'reject'){
$stmt = $conn->prepare("UPDATE returns SET status='REJECTED' WHERE return_id=?");
$stmt->bind_param("i", $return_id);
$stmt->execute();
$stmt->close();
}
}

$type = ($action === 'approve') ? 'RETURN_APPROVED' : 'RETURN_REJECTED';
$desc = strtoupper($type)." for Return #{$return_id}";
$stmt = $conn->prepare("INSERT INTO activity_logs (user_id, activity_type, description, created_at) VALUES (?, ?, ?, NOW())");
$stmt->bind_param("iss", $_SESSION['user_id'], $type, $desc);
$stmt->execute();
$stmt->close();

header("Location: returns_report.php");
exit;
}

$status = trim($_GET['status'] ?? 'all');
$allowed = ['all','pending','approved','rejected','completed','refunded'];
if(!in_array(strtolower($status), $allowed)) $status = 'all';

$where = "";
if(strtolower($status) !== 'all'){
  $s = $conn->real_escape_string($status);
  $where = " WHERE LOWER(r.status) = '$s' ";
}

$summary = $conn->query("
  SELECT 
    SUM(CASE WHEN LOWER(status)='pending' THEN 1 ELSE 0 END) AS pending_cnt,
    SUM(CASE WHEN LOWER(status)='approved' THEN 1 ELSE 0 END) AS approved_cnt,
    SUM(CASE WHEN LOWER(status)='rejected' THEN 1 ELSE 0 END) AS rejected_cnt
  FROM returns
")->fetch_assoc();

$res = $conn->query("
  SELECT r.return_id, r.sale_id, r.qty_returned, r.reason, r.return_date, r.status,
         p.variety, p.grade,
         CONCAT(c.first_name,' ',c.last_name) AS customer
  FROM returns r
  LEFT JOIN products p ON p.product_id=r.product_id
  LEFT JOIN sales s ON s.sale_id=r.sale_id
  LEFT JOIN customers c ON c.customer_id=s.customer_id
  $where
  ORDER BY r.return_date DESC, r.return_id DESC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Returns Report | Owner</title>
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
      <h3 class="fw-bold mb-1">Returns Report</h3>
      <div class="text-muted">Monitor return requests and trends (read-only).</div>
    </div>
    <button class="btn btn-outline-dark" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
      <div class="card modern-card"><div class="card-body">
        <div class="text-muted">Pending</div>
        <div class="h3 fw-bold mb-0"><?= (int)($summary['pending_cnt'] ?? 0) ?></div>
      </div></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card modern-card"><div class="card-body">
        <div class="text-muted">Approved</div>
        <div class="h3 fw-bold mb-0"><?= (int)($summary['approved_cnt'] ?? 0) ?></div>
      </div></div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card modern-card"><div class="card-body">
        <div class="text-muted">Rejected</div>
        <div class="h3 fw-bold mb-0"><?= (int)($summary['rejected_cnt'] ?? 0) ?></div>
      </div></div>
    </div>
  </div>

  <div class="card modern-card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-md-6">
          <label class="form-label fw-semibold">Filter Status</label>
          <select class="form-select form-select-lg" name="status">
            <?php
              $opts = ['all'=>'All','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','completed'=>'Completed','refunded'=>'Refunded'];
              foreach($opts as $k=>$v){
                $sel = (strtolower($status)===$k) ? 'selected' : '';
                echo "<option value='$k' $sel>$v</option>";
              }
            ?>
          </select>
        </div>
        <div class="col-12 col-md-6 d-grid">
          <button class="btn btn-dark btn-lg"><i class="fa-solid fa-filter me-1"></i> Apply</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card modern-card">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>Date</th>
              <th>Return ID</th>
              <th>Sale ID</th>
              <th>Customer</th>
              <th>Product</th>
              <th class="text-end">Qty (kg)</th>
              <th>Status</th>
              <th>Reason</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if($res && $res->num_rows>0): ?>
            <?php while($r=$res->fetch_assoc()): ?>
              <?php
                $st = strtolower(trim($r['status'] ?? 'pending'));
                $badge = 'bg-secondary';
                if($st==='pending') $badge='bg-warning text-dark';
                elseif($st==='approved') $badge='bg-success';
                elseif($st==='rejected') $badge='bg-danger';
              ?>
              <tr>
                <td><?= $r['return_date'] ? htmlspecialchars(date("M d, Y", strtotime($r['return_date']))) : '—' ?></td>
                <td class="fw-semibold">#<?= (int)$r['return_id'] ?></td>
                <td>#<?= (int)$r['sale_id'] ?></td>
                <td><?= htmlspecialchars($r['customer'] ?: '—') ?></td>
                <td><?= htmlspecialchars(trim(($r['variety'] ?? 'N/A')." - ".($r['grade'] ?? ''))) ?></td>
                <td class="text-end fw-bold"><?= number_format((float)$r['qty_returned'],2) ?></td>
                <td><span class="badge <?= $badge ?>"><?= strtoupper($st) ?></span></td>
                <td><?= htmlspecialchars($r['reason'] ?? '') ?></td>
                <td>
                  <?php if($st === 'pending'): ?>
                  <form method="POST" class="d-flex gap-1">
                    <input type="hidden" name="return_id" value="<?= (int)$r['return_id'] ?>">
                    <button type="submit" name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                    <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger">Reject</button>
                  </form>
                  <?php else: ?>
                  —
                  <?php endif; ?>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="9" class="text-center text-muted">No returns found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
