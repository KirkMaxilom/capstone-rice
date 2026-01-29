<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit;
}
if(strtolower($_SESSION['role'] ?? '') !== 'owner'){
    header("Location: ../login.php");
    exit;
}

$username = $_SESSION['username'] ?? 'Owner';
include '../config/db.php';

/* =========================
   OWNER DASHBOARD METRICS
   (READ-ONLY + FINANCE VIEW)
========================= */

// Total products (not archived)
$totalProductsRow = $conn->query("SELECT COUNT(*) AS cnt FROM products WHERE archived=0");
$totalProducts = $totalProductsRow ? (int)$totalProductsRow->fetch_assoc()['cnt'] : 0;

// Total stock (estimated) from inventory_transactions (IN - OUT + ADJUST)
$totalStockRow = $conn->query("
    SELECT IFNULL(SUM(
        CASE 
            WHEN LOWER(type)='in' THEN qty_kg
            WHEN LOWER(type)='out' THEN -qty_kg
            WHEN LOWER(type)='adjust' THEN qty_kg
            ELSE 0
        END
    ),0) AS total_stock
    FROM inventory_transactions
");
$totalStock = $totalStockRow ? (float)$totalStockRow->fetch_assoc()['total_stock'] : 0.0;

// Sales today (qty and revenue)
$salesTodayRow = $conn->query("
    SELECT 
        IFNULL(SUM(si.qty_kg),0) AS sold_kg,
        IFNULL(SUM(s.total_amount),0) AS revenue
    FROM sales s
    LEFT JOIN sales_items si ON s.sale_id = si.sale_id
    WHERE DATE(s.sale_date) = CURDATE()
      AND (s.status IS NULL OR LOWER(s.status) <> 'cancelled')
");
$salesToday = $salesTodayRow ? $salesTodayRow->fetch_assoc() : ['sold_kg'=>0,'revenue'=>0];

// Sales this month (revenue)
$salesMonthRow = $conn->query("
    SELECT IFNULL(SUM(total_amount),0) AS revenue_month
    FROM sales
    WHERE YEAR(sale_date) = YEAR(CURDATE())
      AND MONTH(sale_date) = MONTH(CURDATE())
      AND (status IS NULL OR LOWER(status) <> 'cancelled')
");
$revenueMonth = $salesMonthRow ? (float)$salesMonthRow->fetch_assoc()['revenue_month'] : 0.0;

// Pending returns count
$pendingReturnsRow = $conn->query("SELECT COUNT(*) AS cnt FROM returns WHERE LOWER(status)='pending'");
$pendingReturns = $pendingReturnsRow ? (int)$pendingReturnsRow->fetch_assoc()['cnt'] : 0;

/* =========================
   AR / AP SUMMARY (OWNER)
========================= */
$arRow = $conn->query("
  SELECT
    IFNULL(SUM(total_amount),0) AS total_ar,
    IFNULL(SUM(balance),0) AS balance_ar
  FROM account_receivable
");
$ar = $arRow ? $arRow->fetch_assoc() : ['total_ar'=>0,'balance_ar'=>0];

$apRow = $conn->query("
  SELECT
    IFNULL(SUM(total_amount),0) AS total_ap,
    IFNULL(SUM(balance),0) AS balance_ap
  FROM account_payable
");
$ap = $apRow ? $apRow->fetch_assoc() : ['total_ap'=>0,'balance_ap'=>0];

// Top product (all-time sold kg)
$topProductRow = $conn->query("
    SELECT p.variety, p.grade, IFNULL(SUM(si.qty_kg),0) AS total_sold
    FROM sales_items si
    JOIN sales s ON si.sale_id = s.sale_id
    JOIN products p ON si.product_id = p.product_id
    WHERE (s.status IS NULL OR LOWER(s.status) <> 'cancelled')
    GROUP BY p.product_id
    ORDER BY total_sold DESC
    LIMIT 1
");
$topProduct = $topProductRow && $topProductRow->num_rows ? $topProductRow->fetch_assoc() : null;

// Recent inventory movements (timeline preview)
$recentInventory = $conn->query("
    SELECT it.created_at, it.product_id, it.qty_kg, it.type, it.reference_type, it.reference_id, it.note,
           p.variety, p.grade
    FROM inventory_transactions it
    LEFT JOIN products p ON it.product_id = p.product_id
    ORDER BY it.created_at DESC
    LIMIT 8
");

// Sales over time (monthly) for chart
$months = [];
$salesData = [];
$monthly = $conn->query("
    SELECT DATE_FORMAT(s.sale_date,'%b %Y') AS month,
           IFNULL(SUM(si.qty_kg),0) AS total
    FROM sales_items si
    JOIN sales s ON si.sale_id = s.sale_id
    WHERE (s.status IS NULL OR LOWER(s.status) <> 'cancelled')
    GROUP BY YEAR(s.sale_date), MONTH(s.sale_date)
    ORDER BY YEAR(s.sale_date), MONTH(s.sale_date)
    LIMIT 12
");
if($monthly){
    while($r = $monthly->fetch_assoc()){
        $months[] = $r['month'];
        $salesData[] = (float)$r['total'];
    }
}

// Forecast placeholder
$forecastLabels = ["Next Month","+2 Months","+3 Months"];
$forecastValues = [0,0,0];
if(count($salesData) > 0){
    $last = (float)$salesData[count($salesData)-1];
    $forecastValues = [
        round($last * 1.03, 2),
        round($last * 1.05, 2),
        round($last * 1.08, 2),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Owner Dashboard | DOHIVES</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
.bg-gradient-danger {background:linear-gradient(135deg,#e52d27,#b31217);}

.table td, .table th { padding:0.55rem; vertical-align: middle; }
.badge-soft { background: rgba(25,135,84,.15); color:#198754; }

.main-content {
    padding-top: 70px;
    padding-left: 20px;
    padding-right: 20px;
}
</style>
</head>

<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<!-- TOP NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top" style="margin-left: 240px; width: calc(100% - 240px); z-index: 1020;">
  <div class="container-fluid">
    <span class="navbar-brand fw-bold ms-2">DE ORO HIYS GENERAL MERCHANDISE</span>

    <div class="ms-auto dropdown">
      <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#">
        <?= htmlspecialchars($username) ?> <small class="text-muted">(Owner)</small>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i>Profile</a></li>
        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- MAIN CONTENT -->
<main class="main-content">
<div class="py-4">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <h3 class="fw-bold mb-1">Owner Overview</h3>
      <div class="text-muted">Monitoring & decision dashboard</div>
    </div>
    <span class="badge rounded-pill bg-dark px-3 py-2">
      <i class="fa-solid fa-chart-simple me-1"></i> Overview
    </span>
  </div>

  <!-- SUMMARY CARDS (NOW 6 CARDS) -->
  <div class="row g-4">
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card bg-gradient-primary text-white p-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-light">Total Products</div>
            <div class="display-6 fw-bold"><?= $totalProducts ?></div>
          </div>
          <i class="fas fa-box fa-3x opacity-75"></i>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card bg-gradient-success text-white p-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-light">Estimated Stock</div>
            <div class="display-6 fw-bold"><?= number_format($totalStock,2) ?> <small class="fs-6">kg</small></div>
          </div>
          <i class="fas fa-warehouse fa-3x opacity-75"></i>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card bg-gradient-info text-white p-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-light">Sales Today</div>
            <div class="h3 fw-bold mb-0"><?= number_format((float)$salesToday['sold_kg'],2) ?> kg</div>
            <div class="small opacity-75">₱<?= number_format((float)$salesToday['revenue'],2) ?></div>
          </div>
          <i class="fas fa-cash-register fa-3x opacity-75"></i>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card bg-gradient-warning text-dark p-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-light">Revenue (This Month)</div>
            <div class="h3 fw-bold mb-0">₱<?= number_format($revenueMonth,2) ?></div>
            <div class="small">Based on sales records</div>
          </div>
          <i class="fas fa-chart-column fa-3x opacity-75"></i>
        </div>
      </div>
    </div>

    <!-- AR CARD -->
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card bg-gradient-warning text-dark p-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-light">Accounts Receivable (AR)</div>
            <div class="h4 fw-bold mb-0">₱<?= number_format((float)($ar['balance_ar'] ?? 0),2) ?></div>
            <div class="small">Total: ₱<?= number_format((float)($ar['total_ar'] ?? 0),2) ?></div>
          </div>
          <i class="fas fa-hand-holding-dollar fa-3x opacity-75"></i>
        </div>
      </div>
    </div>

    <!-- AP CARD -->
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card bg-gradient-danger text-white p-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-light">Accounts Payable (AP)</div>
            <div class="h4 fw-bold mb-0">₱<?= number_format((float)($ap['balance_ap'] ?? 0),2) ?></div>
            <div class="small opacity-75">Total: ₱<?= number_format((float)($ap['total_ap'] ?? 0),2) ?></div>
          </div>
          <i class="fas fa-file-invoice-dollar fa-3x opacity-75"></i>
        </div>
      </div>
    </div>

    <!-- Pending Returns -->
    <div class="col-12 col-md-6 col-xl-3">
      <div class="card modern-card bg-gradient-warning text-dark p-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-light">Pending Returns</div>
            <div class="display-6 fw-bold"><?= $pendingReturns ?></div>
            <div class="small">Waiting for approval</div>
          </div>
          <i class="fas fa-rotate-left fa-3x opacity-75"></i>
        </div>
      </div>
    </div>

  </div>

  <!-- SECOND ROW -->
  <div class="row g-4 mt-1">
    <!-- CHART: SALES OVER TIME -->
    <div class="col-12 col-xl-7">
      <div class="card modern-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0">Sales Over Time (kg)</h5>
            <span class="text-muted small">Last 12 months</span>
          </div>
          <canvas id="salesChart" height="120"></canvas>
          <div class="mt-3 text-muted small">
            <i class="fa-solid fa-info-circle me-1"></i>
            Uses actual sales data (sales + sales_items). If no data yet, chart will be empty.
          </div>
        </div>
      </div>
    </div>

    <!-- TOP PRODUCT + FORECAST -->
    <div class="col-12 col-xl-5">
      <div class="card modern-card mb-4">
        <div class="card-body">
          <h5 class="fw-bold mb-2">Top Selling Product</h5>
          <?php if($topProduct): ?>
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="h4 mb-0"><?= htmlspecialchars($topProduct['variety']) ?> <small class="text-muted">- <?= htmlspecialchars($topProduct['grade']) ?></small></div>
                <div class="text-muted">Total Sold: <?= number_format((float)$topProduct['total_sold'],2) ?> kg</div>
              </div>
              <i class="fa-solid fa-trophy fa-2x text-warning"></i>
            </div>
          <?php else: ?>
            <div class="text-muted">No sales data yet.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card modern-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0">Forecast (Placeholder)</h5>
            <span class="badge badge-soft">Coming soon</span>
          </div>
          <canvas id="forecastChart" height="140"></canvas>
          <div class="mt-3 text-muted small">
            <i class="fa-solid fa-flask me-1"></i>
            Forecasting will be enabled after data gathering (e.g., at least 2–3 months of sales history).
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- RECENT INVENTORY TIMELINE -->
  <div class="card modern-card mt-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h5 class="fw-bold mb-0">Recent Inventory Movements</h5>
        <a class="btn btn-sm btn-outline-dark" href="inventory_monitoring.php">
          <i class="fa-solid fa-arrow-right me-1"></i> View Full Monitoring
        </a>
      </div>

      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>Date</th>
              <th>Product</th>
              <th>Qty</th>
              <th>Type</th>
              <th>Reference</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if($recentInventory && $recentInventory->num_rows > 0): ?>
              <?php while($row = $recentInventory->fetch_assoc()): ?>
                <?php
                  $dt = $row['created_at'] ? date("M d, Y h:i A", strtotime($row['created_at'])) : '';
                  $prod = trim(($row['variety'] ?? 'N/A') . " - " . ($row['grade'] ?? ''));
                  $t = strtolower(trim($row['type'] ?? ''));
                  $qtyNum = (float)($row['qty_kg'] ?? 0);

                  if($t === 'adjust'){
                      $sign = ($qtyNum >= 0) ? '+' : '-';
                      $qty = $sign . number_format(abs($qtyNum),2) . " kg";
                  } else {
                      $sign = ($t === 'in') ? '+' : (($t === 'out') ? '-' : '');
                      $qty = $sign . number_format(abs($qtyNum),2) . " kg";
                  }

                  $ref = strtoupper((string)($row['reference_type'] ?? ''));
                  $refId = $row['reference_id'] !== null ? ("#".$row['reference_id']) : '';
                  $note = $row['note'] ?? '';
                ?>
                <tr>
                  <td><?= htmlspecialchars($dt) ?></td>
                  <td><?= htmlspecialchars($prod) ?></td>
                  <td class="fw-bold"><?= htmlspecialchars($qty) ?></td>
                  <td>
                    <?php if($t === 'in'): ?>
                      <span class="badge bg-success">IN</span>
                    <?php elseif($t === 'out'): ?>
                      <span class="badge bg-danger">OUT</span>
                    <?php else: ?>
                      <span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($t ?: 'N/A')) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars(trim($ref . " " . $refId)) ?></td>
                  <td><?= htmlspecialchars($note) ?></td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="6" class="text-center text-muted">No inventory transactions yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

    </div>
  </div>

</div>
</main>

<script>
// Sales chart
new Chart(document.getElementById('salesChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($months) ?>,
    datasets: [{
      data: <?= json_encode($salesData) ?>,
      tension: 0.4,
      fill: false
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true } }
  }
});

// Forecast placeholder chart
new Chart(document.getElementById('forecastChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($forecastLabels) ?>,
    datasets: [{
      data: <?= json_encode($forecastValues) ?>
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true } }
  }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>