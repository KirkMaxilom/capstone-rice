<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}
$username = $_SESSION['username'] ?? 'User';
include '../config/db.php';
require_once 'InventoryAnalytics.php';

/* =========================
   SALES PER PRODUCT
========================= */
$salesPerProduct = [];
$sql = "
SELECT p.variety, SUM(si.qty_kg) AS total_sold
FROM sales_items si
JOIN sales s ON si.sale_id = s.sale_id
JOIN products p ON si.product_id = p.product_id
WHERE s.status != 'cancelled'
GROUP BY p.variety
ORDER BY total_sold DESC
";
$result = $conn->query($sql);
if($result){
  while($row = $result->fetch_assoc()){
      $salesPerProduct[] = $row;
  }
}

/* =========================
   SALES OVER TIME (MONTHLY)
========================= */
$months = [];
$salesData = [];
$sql = "
SELECT DATE_FORMAT(s.sale_date,'%b %Y') AS month,
       SUM(si.qty_kg) AS total
FROM sales_items si
JOIN sales s ON si.sale_id = s.sale_id
WHERE s.status != 'cancelled'
GROUP BY YEAR(s.sale_date), MONTH(s.sale_date)
ORDER BY YEAR(s.sale_date), MONTH(s.sale_date)
";
$result = $conn->query($sql);
if($result){
  while($row = $result->fetch_assoc()){
      $months[] = $row['month'];
      $salesData[] = (float)$row['total'];
  }
}

/* =========================
   SUMMARY DATA
========================= */
$topProduct = $salesPerProduct[0]['variety'] ?? 'N/A';
$totalSales = array_sum($salesData);

$growth = 0;
$count = count($salesData);
if($count >= 2 && $salesData[$count-2] > 0){
    $growth = (($salesData[$count-1] - $salesData[$count-2]) / $salesData[$count-2]) * 100;
}

/* =========================
   FORECAST LOGIC (VIA SERVICE)
========================= */
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

// Use the service function
$forecastData = calculateForecastFromMonthlySales($salesData, 3);

// Combined labels + values for a single chart with forecast continuation
$combinedLabels = array_merge($months, $forecastLabels);
$combinedActual = $salesData;

// Pad actual with nulls so the actual line stops before forecast begins
$combinedActualPadded = array_merge($combinedActual, array_fill(0, count($forecastLabels), null));

// Forecast line: put nulls for past months, then forecast values
$combinedForecastPadded = array_merge(array_fill(0, count($months), null), $forecastData);

// Forecast table rows
$forecastTable = [];
for($i=0;$i<count($forecastLabels);$i++){
    $forecastTable[] = [
        'month' => $forecastLabels[$i],
        'pred' => $forecastData[$i]
    ];
}

/* =========================
   INVENTORY HEALTH (DAYS OF COVER)
========================= */
$productStock = [];
// Fetch current stock. Note: Using defaults for lead/safety days to ensure compatibility.
$sql = "SELECT product_id, variety, grade, stock_kg FROM products WHERE archived = 0";
$res = $conn->query($sql);
if($res){
    while ($row = $res->fetch_assoc()) {
        $productStock[$row['product_id']] = $row;
    }
}

$inventoryMetrics = [];
foreach ($productStock as $pid => $p) {
    $metrics = calculateDaysOfCover(
        (float)$p['stock_kg'],
        $forecastData[0], // Using global forecast as baseline
        7, // Default Lead Time (days)
        3  // Default Safety Stock (days)
    );
    $inventoryMetrics[$pid] = array_merge($p, $metrics);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Analytics | DOHIVES</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
body { background:#f4f6f9; }

.main-content { padding-top:70px; }

/* Analytics */
.analytics-row { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.analytics-box {
    background:#fff;
    padding:20px;
    border-radius:14px;
    box-shadow:0 6px 16px rgba(0,0,0,.12);
}
.bar { background:#eaeaea; border-radius:6px; overflow:hidden; }
.bar div { background:#2f5bff; color:#fff; padding:4px 8px; font-size:.8rem; }

.risk-red { background-color: #ffebee; color: #c62828; border-left: 4px solid #c62828; }
.risk-yellow { background-color: #fff3e0; color: #ef6c00; border-left: 4px solid #ef6c00; }
.risk-green { background-color: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }

.analytics-summary { display:flex; gap:40px; margin-top:30px; flex-wrap:wrap; }
.positive { color:green; }

.small-note {
    font-size:.85rem;
    color:#6c757d;
}

@media (max-width: 992px){
  .analytics-row{ grid-template-columns:1fr; }
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
        <?= htmlspecialchars($username) ?>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="profile.php">Profile</a></li>
        <li><a class="dropdown-item text-danger" href="../logout.php">Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- MAIN CONTENT -->
<main class="main-content px-4">

<h3 class="fw-bold mb-2">Smart Analytics</h3>
<p>Sales trends, forecasting, and reports</p>

<div class="analytics-row">

<!-- SALES PER PRODUCT -->
<div class="analytics-box">
  <h5 class="fw-bold mb-3">Sales per Product (kg)</h5>

  <?php if(empty($salesPerProduct)): ?>
    <div class="alert alert-warning mb-0">
      No sales data yet. This section will populate once transactions are recorded.
    </div>
  <?php else: ?>
    <?php
      $maxSold = max(array_map(fn($r)=>(float)$r['total_sold'], $salesPerProduct));
      if($maxSold <= 0) $maxSold = 1;
    ?>
    <?php foreach($salesPerProduct as $row): ?>
      <?php
        $pct = ((float)$row['total_sold'] / $maxSold) * 100;
      ?>
      <div class="mb-2">
        <span><?= htmlspecialchars($row['variety']) ?></span>
        <div class="bar">
          <div style="width:<?= max(5, min(100, $pct)) ?>%">
            <?= number_format((float)$row['total_sold'],2) ?> kg
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- SALES + FORECAST CHART -->
<div class="analytics-box">
  <div class="d-flex justify-content-between align-items-start">
    <div>
      <h5 class="fw-bold mb-1">Sales Over Time + Forecast</h5>
      <div class="small-note">
        Forecast is a placeholder (Simple Moving Average). Replace later with your forecasting model/data gathering.
      </div>
    </div>
  </div>
  <canvas id="salesChart" height="180"></canvas>

  <!-- Forecast Table -->
  <div class="mt-3">
    <h6 class="fw-bold mb-2">Forecast (Next 3 Months)</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-light">
          <tr>
            <th>Month</th>
            <th>Predicted Demand (kg)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($forecastTable as $f): ?>
            <tr>
              <td><?= htmlspecialchars($f['month']) ?></td>
              <td><?= number_format((float)$f['pred'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- end analytics-row -->

<!-- INVENTORY HEALTH SECTION -->
<div class="analytics-box mt-4">
  <h5 class="fw-bold mb-3">Inventory Health (Days of Cover)</h5>
  <p class="text-muted small">Estimated coverage based on next month's global forecast demand.</p>
  
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
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

<!-- SUMMARY -->
<div class="analytics-summary">
  <div>
    <small>Growth (Last vs Prev Month)</small>
    <h3 class="<?= $growth >= 0 ? 'positive' : 'text-danger' ?>">
      <?= number_format($growth,1) ?>%
    </h3>
  </div>
  <div>
    <small>Top Sales Product</small>
    <h3><?= htmlspecialchars($topProduct) ?></h3>
  </div>
  <div>
    <small>Total Sold</small>
    <h3><?= number_format((float)$totalSales,2) ?> kg</h3>
  </div>
  <div>
    <small>Next Month Forecast</small>
    <h3><?= number_format((float)$forecastData[0],2) ?> kg</h3>
  </div>
</div>

</main>
</div>
</div>

<script>
new Chart(document.getElementById('salesChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($combinedLabels) ?>,
    datasets: [
      {
        label: 'Actual',
        data: <?= json_encode($combinedActualPadded) ?>,
        borderColor: '#2f5bff',
        tension: 0.4,
        fill: false
      },
      {
        label: 'Forecast (Placeholder)',
        data: <?= json_encode($combinedForecastPadded) ?>,
        borderColor: '#fd7e14',
        borderDash: [6,6],
        tension: 0.4,
        fill: false
      }
    ]
  },
  options: {
    plugins: { legend: { display: true } },
    scales: { y: { beginAtZero: true } }
  }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
