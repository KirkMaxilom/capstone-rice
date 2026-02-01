<?php
session_start();
if(!isset($_SESSION['user_id'])){ header("Location: ../login.php"); exit; }
if(strtolower($_SESSION['role'] ?? '') !== 'owner'){ header("Location: ../login.php"); exit; }

$username = $_SESSION['username'] ?? 'Owner';
include '../config/db.php';
require_once '../admin/InventoryAnalytics.php';

/* =========================
   ANALYTICS (Last 12 months)
========================= */

// Monthly KG + Revenue (use sales_items line_total to avoid double-count)
$months = [];
$kgData = [];
$revData = [];

$monthly = $conn->query("
  SELECT 
    DATE_FORMAT(s.sale_date,'%b %Y') AS month_label,
    YEAR(s.sale_date) AS y,
    MONTH(s.sale_date) AS m,
    COALESCE(SUM(si.qty_kg),0) AS total_kg,
    COALESCE(SUM(si.line_total),0) AS total_rev
  FROM sales s
  JOIN sales_items si ON si.sale_id = s.sale_id
  WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND (s.status IS NULL OR LOWER(s.status) <> 'cancelled')
  GROUP BY y, m, month_label
  ORDER BY y, m
  LIMIT 12
");

if($monthly){
  while($r = $monthly->fetch_assoc()){
    $months[] = $r['month_label'];
    $kgData[] = (float)$r['total_kg'];
    $revData[] = (float)$r['total_rev'];
  }
}

// Summary totals (same range)
$summary = $conn->query("
  SELECT 
    COALESCE(SUM(si.qty_kg),0) AS total_kg,
    COALESCE(SUM(si.line_total),0) AS total_rev,
    COUNT(DISTINCT s.sale_id) AS total_sales
  FROM sales s
  JOIN sales_items si ON si.sale_id = s.sale_id
  WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND (s.status IS NULL OR LOWER(s.status) <> 'cancelled')
")->fetch_assoc();

$totalKg12 = (float)($summary['total_kg'] ?? 0);
$totalRev12 = (float)($summary['total_rev'] ?? 0);
$totalSales12 = (int)($summary['total_sales'] ?? 0);

// Forecast Logic (Real)
function nextMonthsLabels($n = 3){
    $labels = [];
    $dt = new DateTime('first day of this month');
    for($i=1;$i<=$n;$i++){
        $dt->modify('+1 month');
        $labels[] = $dt->format('M Y');
    }
    return $labels;
}
$forecastLabels = nextMonthsLabels(3);
$forecastKg = calculateForecastFromMonthlySales($kgData, 3);
$forecastRev = calculateForecastFromMonthlySales($revData, 3);

/* =========================
   INVENTORY HEALTH (DAYS OF COVER)
========================= */
$productStock = [];
$sql = "SELECT product_id, variety, grade, stock_kg FROM products WHERE archived = 0";
$res = $conn->query($sql);
if($res){
    while ($row = $res->fetch_assoc()) {
        $productStock[$row['product_id']] = $row;
    }
}

$inventoryMetrics = [];
foreach ($productStock as $pid => $p) {
    // Using global forecast (kg) as baseline for demand
    $metrics = calculateDaysOfCover((float)$p['stock_kg'], $forecastKg[0], 7, 3);
    $inventoryMetrics[$pid] = array_merge($p, $metrics);
}

// Avg price per kg (rough KPI)
$avgPricePerKg = ($totalKg12 > 0) ? ($totalRev12 / $totalKg12) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Analytics & Forecasting | Owner</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
body { background:#f4f6f9; }

.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); }
.main-content { padding-top:70px; padding-left: 20px; padding-right: 20px; }

.badge-soft { background: rgba(25,135,84,.15); color:#198754; }

.risk-red { background-color: #ffebee; color: #c62828; border-left: 4px solid #c62828; }
.risk-yellow { background-color: #fff3e0; color: #ef6c00; border-left: 4px solid #ef6c00; }
.risk-green { background-color: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }
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
      <h3 class="fw-bold mb-1">Analytics & Forecasting</h3>
      <div class="text-muted">Summary and trends based on the last 12 months (read-only).</div>
    </div>
    <button class="btn btn-outline-dark" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Print</button>
  </div>

  <!-- KPI ROW -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
      <div class="card modern-card">
        <div class="card-body">
          <div class="text-muted">Total Transactions (12 months)</div>
          <div class="h3 fw-bold mb-0"><?= $totalSales12 ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card modern-card">
        <div class="card-body">
          <div class="text-muted">Total Sold (kg) (12 months)</div>
          <div class="h3 fw-bold mb-0"><?= number_format($totalKg12,2) ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card modern-card">
        <div class="card-body">
          <div class="text-muted">Total Revenue (12 months)</div>
          <div class="h3 fw-bold mb-0">₱<?= number_format($totalRev12,2) ?></div>
          <div class="small text-muted">Avg price/kg: ₱<?= number_format($avgPricePerKg,2) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- CHARTS -->
  <div class="row g-4">
    <div class="col-12 col-xl-7">
      <div class="card modern-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0">Sales Trend (kg)</h5>
            <span class="text-muted small">Last 12 months</span>
          </div>
          <canvas id="kgChart" height="120"></canvas>
          <div class="mt-3 text-muted small">
            <i class="fa-solid fa-info-circle me-1"></i>
            Shows total kg sold per month from sales_items.
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-5">
      <div class="card modern-card mb-4">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0">Revenue Trend (₱)</h5>
            <span class="text-muted small">Last 12 months</span>
          </div>
          <canvas id="revChart" height="140"></canvas>
        </div>
      </div>

      <div class="card modern-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="fw-bold mb-0">Forecast (Next 3 Months)</h5>
            <span class="badge badge-soft">SMA Model</span>
          </div>
          <canvas id="forecastChart" height="140"></canvas>
          <div class="mt-3 text-muted small">
            <i class="fa-solid fa-flask me-1"></i>
            Projection based on Simple Moving Average (SMA) of last 3 months.
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- INVENTORY HEALTH SECTION -->
  <div class="card modern-card mt-4">
    <div class="card-body">
      <h5 class="fw-bold mb-3">Inventory Health (Days of Cover)</h5>
      <p class="text-muted small">Estimated coverage based on next month's global forecast demand.</p>
      
      <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Current Stock</th>
              <th>Est. Daily Demand</th>
              <th>Days of Cover</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($inventoryMetrics as $m): ?>
              <?php 
                $riskClass = 'risk-'.$m['risk_band']; 
                $statusLabel = strtoupper($m['risk_band'] === 'red' ? 'Critical' : ($m['risk_band'] === 'yellow' ? 'Low' : 'Healthy'));
              ?>
              <tr class="<?= $riskClass ?>">
                <td><?= htmlspecialchars($m['variety'] . ' - ' . $m['grade']) ?></td>
                <td><?= number_format($m['stock_kg'], 2) ?> kg</td>
                <td><?= $m['forecast_daily'] ?> kg/day</td>
                <td class="fw-bold"><?= $m['days_of_cover'] ?> days</td>
                <td><?= $statusLabel ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</main>

<script>
const months = <?= json_encode($months) ?>;
const kgData = <?= json_encode($kgData) ?>;
const revData = <?= json_encode($revData) ?>;

new Chart(document.getElementById('kgChart'), {
  type: 'line',
  data: { labels: months, datasets: [{ data: kgData, tension: 0.35, fill: false }] },
  options: { plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true } } }
});

new Chart(document.getElementById('revChart'), {
  type: 'bar',
  data: { labels: months, datasets: [{ data: revData }] },
  options: { plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true } } }
});

new Chart(document.getElementById('forecastChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($forecastLabels) ?>,
    datasets: [
      { label: 'Forecast kg', data: <?= json_encode($forecastKg) ?> },
      { label: 'Forecast revenue', data: <?= json_encode($forecastRev) ?> }
    ]
  },
  options: {
    plugins:{ legend:{ display:true } },
    scales:{ y:{ beginAtZero:true } }
  }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
