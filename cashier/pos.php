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

/*
CASHIER POS (New Sale)
- Creates sale + sales_items
- Deducts inventory via inventory_transactions (type='out')
- If UNPAID (utang) -> creates account_receivable record
NOTES:
- Uses inventory_transactions as source of truth for stock
*/

/* -------------------------
Helpers
------------------------- */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* Preserve only header fields (customer/type/due date) across error refresh */
function pos_save_old($customer_id, $sale_type, $due_date){
  $_SESSION['pos_old'] = [
    'customer_id' => (int)$customer_id,
    'sale_type' => (string)$sale_type,
    'due_date' => (string)$due_date,
  ];
}

/* Auto compute due date based on kinsenas: 2, 15, 25, 30 */
function compute_kinsenas_due_date(): string {
  $today = new DateTime(); // server date/time
  $year  = (int)$today->format('Y');
  $month = (int)$today->format('m');
  $day   = (int)$today->format('d');

  $kinsenas = [2, 15, 25, 30];

  // Find next upcoming kinsenas in this month
  foreach ($kinsenas as $kday) {
    if ($day <= $kday) {
      $lastDay = (int)$today->format('t'); // last day of this month
      if ($kday <= $lastDay) {
        return sprintf('%04d-%02d-%02d', $year, $month, $kday);
      }
    }
  }

  // If none left this month -> due on 2nd of next month
  $nextMonth = $month + 1;
  $nextYear  = $year;
  if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
  }

  return sprintf('%04d-%02d-%02d', $nextYear, $nextMonth, 2);
}

/* -------------------------
Load Products with computed stock
stock = SUM(in) - SUM(out) + SUM(adjust)
------------------------- */
$products = [];
$sqlProducts = "
SELECT
  p.product_id, p.variety, p.grade, p.sku, p.unit_price,
  IFNULL(SUM(
    CASE
      WHEN LOWER(it.type)='in' THEN it.qty_kg
      WHEN LOWER(it.type)='out' THEN -it.qty_kg
      WHEN LOWER(it.type)='adjust' THEN it.qty_kg
      ELSE 0
    END
  ),0) AS stock_kg
FROM products p
LEFT JOIN inventory_transactions it ON it.product_id = p.product_id
WHERE p.archived = 0
GROUP BY p.product_id
ORDER BY p.variety ASC
";
$resP = $conn->query($sqlProducts);
if($resP){
  while($row = $resP->fetch_assoc()){
    $products[] = $row;
  }
}

/* -------------------------
Load Customers (optional)
------------------------- */
$customers = [];
$resC = $conn->query("SELECT customer_id, first_name, last_name, phone FROM customers ORDER BY created_at DESC");
if($resC){
  while($r = $resC->fetch_assoc()) $customers[] = $r;
}

/* -------------------------
Create new customer (quick add)
------------------------- */
if(isset($_POST['add_customer'])){
  $fn = trim($_POST['first_name'] ?? '');
  $ln = trim($_POST['last_name'] ?? '');
  $ph = trim($_POST['phone'] ?? '');
  $ad = trim($_POST['address'] ?? '');

  if($fn !== '' && $ln !== ''){
    $stmt = $conn->prepare("INSERT INTO customers (first_name,last_name,phone,address,created_at) VALUES (?,?,?,?,NOW())");
    $stmt->bind_param("ssss", $fn, $ln, $ph, $ad);
    $stmt->execute();
    $stmt->close();
  }
  header("Location: pos.php?success=" . urlencode("Customer added."));
  exit;
}

/* -------------------------
Messages + restore header fields (customer/type/due date)
------------------------- */
$err = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

$old = $_SESSION['pos_old'] ?? [];
unset($_SESSION['pos_old']);

$old_customer_id = (int)($old['customer_id'] ?? 0);
$old_sale_type = strtolower(trim($old['sale_type'] ?? 'cash'));
$old_due_date = (string)($old['due_date'] ?? '');

/* If old due date missing and old type is utang, show computed due date in UI */
if($old_sale_type === 'utang' && $old_due_date === ''){
  $old_due_date = compute_kinsenas_due_date();
}

/* -------------------------
Handle checkout
------------------------- */
if(isset($_POST['checkout'])){
  $customer_id = (int)($_POST['customer_id'] ?? 0);
  $sale_type = strtolower(trim($_POST['sale_type'] ?? 'cash')); // cash | utang
  $status = ($sale_type === 'utang') ? 'unpaid' : 'paid';

  // AUTO due date for utang (kinsenas 2,15,25,30)
  $due_date_post = '';
  if($sale_type === 'utang'){
    $due_date_post = compute_kinsenas_due_date();
  }

  // items arrays
  $product_ids = $_POST['product_id'] ?? [];
  $qtys = $_POST['qty_kg'] ?? [];
  $prices = $_POST['unit_price'] ?? [];

  // Basic validation (preserve customer/type/due date on errors)
  if($customer_id <= 0){
    pos_save_old($customer_id, $sale_type, $due_date_post);
    header("Location: pos.php?error=" . urlencode("Please select a customer."));
    exit;
  }
  if(!is_array($product_ids) || count($product_ids) === 0){
    pos_save_old($customer_id, $sale_type, $due_date_post);
    header("Location: pos.php?error=" . urlencode("Please add at least one item."));
    exit;
  }

  // Build items list (sanitize) and compute totals
  $items = [];
  $total_amount = 0.0;

  for($i=0; $i<count($product_ids); $i++){
    $pid = (int)($product_ids[$i] ?? 0);
    $qty = (float)($qtys[$i] ?? 0);
    $prc = (float)($prices[$i] ?? 0);

    if($pid <= 0 || $qty <= 0) continue;

    $line = $qty * $prc;
    $total_amount += $line;

    $items[] = [
      'product_id' => $pid,
      'qty_kg' => $qty,
      'unit_price' => $prc,
      'line_total' => $line
    ];
  }

  if(count($items) === 0){
    pos_save_old($customer_id, $sale_type, $due_date_post);
    header("Location: pos.php?error=" . urlencode("No valid items found. Check quantities."));
    exit;
  }

  // Transaction start
  $conn->begin_transaction();

  try {
    // 1) Create sale
    $stmt = $conn->prepare("
      INSERT INTO sales (user_id, customer_id, sale_date, total_amount, status, created_at)
      VALUES (?, ?, NOW(), ?, ?, NOW())
    ");
    $stmt->bind_param("iids", $user_id, $customer_id, $total_amount, $status);
    $stmt->execute();
    $sale_id = $stmt->insert_id;
    $stmt->close();

    // 2) Insert sales_items + inventory OUT
    foreach($items as $it){
      // Check stock (computed from inventory_transactions)
      $check = $conn->prepare("
        SELECT
          IFNULL(SUM(
            CASE
              WHEN LOWER(type)='in' THEN qty_kg
              WHEN LOWER(type)='out' THEN -qty_kg
              WHEN LOWER(type)='adjust' THEN qty_kg
              ELSE 0
            END
          ),0) AS stock_now
        FROM inventory_transactions
        WHERE product_id = ?
      ");
      $check->bind_param("i", $it['product_id']);
      $check->execute();
      $stock_now = (float)($check->get_result()->fetch_assoc()['stock_now'] ?? 0);
      $check->close();

      if($stock_now < $it['qty_kg']){
        throw new Exception("Insufficient stock for product ID {$it['product_id']}. Available: ".number_format($stock_now,2)." kg");
      }

      // sales_items (INSERT ONCE — removed duplicate insert bug)
      $stmt = $conn->prepare("
        INSERT INTO sales_items (sale_id, product_id, qty_kg, unit_price, line_total)
        VALUES (?, ?, ?, ?, ?)
      ");
      $stmt->bind_param("iiddd", $sale_id, $it['product_id'], $it['qty_kg'], $it['unit_price'], $it['line_total']);
      $stmt->execute();
      $stmt->close();

      // inventory OUT transaction
      $note = "Sale #{$sale_id} - deducted stock";
      $stmt = $conn->prepare("
        INSERT INTO inventory_transactions
          (product_id, qty_kg, reference_id, reference_type, type, note, created_at)
        VALUES (?, ?, ?, 'sale', 'out', ?, NOW())
      ");
      // product_id int, qty_kg double, reference_id int, note string => "idis"
      $stmt->bind_param("idis", $it['product_id'], $it['qty_kg'], $sale_id, $note);
      $stmt->execute();
      $stmt->close();
    }

    // 3) If utang -> create AR + push log
    if($sale_type === 'utang'){
      $amount_paid = 0.0;
      $balance = $total_amount;

      // due date always auto-computed; validate format anyway
      $due_date = $due_date_post ?: null;
      if($due_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)){
        $due_date = null;
      }

      $stmt = $conn->prepare("
        INSERT INTO account_receivable
          (sales_id, customer_id, total_amount, amount_paid, balance, due_date, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'unpaid', NOW())
      ");
      $stmt->bind_param("iiddds", $sale_id, $customer_id, $total_amount, $amount_paid, $balance, $due_date);
      $stmt->execute();
      $stmt->close();

      $message = "Hi! You have an unpaid balance of ₱".number_format($balance,2)." for Sale #{$sale_id}. Please settle it by ".($due_date ? $due_date : 'the due date').". Thank you!";

      $stmt = $conn->prepare("
        INSERT INTO push_notif_logs (payment_id, customer_id, message, sent_at, status)
        VALUES (NULL, ?, ?, NOW(), 'SENT')
      ");
      $stmt->bind_param("is", $customer_id, $message);
      $stmt->execute();
      $stmt->close();
    }

    $conn->commit();

    // activity log (after commit)
    $desc = "Created sale #{$sale_id} (".$status.") total ₱".number_format($total_amount,2);
    $stmt = $conn->prepare("
      INSERT INTO activity_logs (user_id, activity_type, description, created_at)
      VALUES (?, 'SALE_CREATE', ?, NOW())
    ");
    $stmt->bind_param("is", $user_id, $desc);
    $stmt->execute();
    $stmt->close();

    // On SUCCESS: page refresh clears everything (new transaction)
    header("Location: pos.php?success=" . urlencode("Sale recorded successfully. Sale #{$sale_id}"));
    exit;

  } catch (Exception $e){
    $conn->rollback();

    // Preserve only customer/type/due date; items will reset (as instructor wants)
    pos_save_old($customer_id, $sale_type, $due_date_post);

    header("Location: pos.php?error=" . urlencode($e->getMessage()));
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>POS | Cashier</title>

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
            <h3 class="fw-bold mb-1">Sale</h3>
            <div class="text-muted">Create sales (Cash or Utang). Stock updates automatically.</div>
          </div>
        </div>

        <?php if($success): ?>
          <div class="alert alert-success py-2"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if($err): ?>
          <div class="alert alert-danger py-2"><?= h($err) ?></div>
        <?php endif; ?>

        <div class="row g-4">
          <!-- LEFT: SALE FORM -->
          <div class="col-12 col-xl-7">
            <div class="card modern-card">
              <div class="card-body">
                <form method="POST" id="saleForm">

                  <div class="mb-3">
                    <label class="form-label fw-semibold">Customer</label>
                    <select name="customer_id" class="form-select" required>
                      <option value="">Select customer</option>
                      <?php foreach($customers as $c): ?>
                        <option value="<?= (int)$c['customer_id'] ?>"
                          <?= ((int)$c['customer_id'] === $old_customer_id) ? 'selected' : '' ?>>
                          <?= h($c['first_name'].' '.$c['last_name']) ?><?= $c['phone'] ? ' - '.h($c['phone']) : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <div class="small-muted mt-1">No customer yet? Add on the right panel.</div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">Sale Type</label>
                    <select name="sale_type" id="sale_type" class="form-select" required onchange="toggleDueDate()">
                      <option value="cash" <?= $old_sale_type==='cash'?'selected':'' ?>>Cash (Paid)</option>
                      <option value="utang" <?= $old_sale_type==='utang'?'selected':'' ?>>Utang (Unpaid)</option>
                    </select>
                  </div>

                  <!-- AUTO DUE DATE DISPLAY (no calendar picking) -->
                  <div class="mb-3 d-none" id="dueDateWrap">
                    <label class="form-label fw-semibold">Auto Due Date (Kinsenas)</label>
                    <input type="text" class="form-control" id="dueDateText" readonly value="<?= h($old_due_date ?: '—') ?>">
                    <div class="small-muted mt-1">
                      System-generated due date based on kinsenas: <b>2, 15, 25, 30</b>.
                    </div>
                  </div>

                  <hr>

                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Items</h5>
                    <button type="button" class="btn btn-sm btn-outline-dark" onclick="addRow()">
                      <i class="fa-solid fa-plus me-1"></i>Add Item
                    </button>
                  </div>

                  <div class="table-responsive">
                    <table class="table table-sm align-middle" id="itemsTable">
                      <thead class="table-dark">
                        <tr>
                          <th style="width:42%">Product</th>
                          <th style="width:16%">Stock</th>
                          <th style="width:14%">Qty (kg)</th>
                          <th style="width:14%">Price</th>
                          <th style="width:14%">Line</th>
                          <th style="width:6%"></th>
                        </tr>
                      </thead>
                      <tbody></tbody>
                    </table>
                  </div>

                  <div class="d-flex justify-content-end mt-2">
                    <div class="text-end">
                      <div class="text-muted">Total Amount</div>
                      <div class="h3 fw-bold mb-0">₱ <span id="grandTotal">0.00</span></div>
                    </div>
                  </div>

                  <input type="hidden" name="checkout" value="1">
                  <button type="submit" class="btn btn-dark w-100 mt-3">
                    <i class="fa-solid fa-check me-1"></i> Checkout
                  </button>

                  <div class="small-muted mt-2">
                    Stock is validated before saving. If insufficient stock, sale will not be recorded.
                  </div>

                </form>
              </div>
            </div>
          </div>

          <!-- RIGHT: QUICK ADD CUSTOMER -->
          <div class="col-12 col-xl-5">
            <div class="card modern-card">
              <div class="card-body">
                <h5 class="fw-bold mb-2">Quick Add Customer</h5>
                <form method="POST">
                  <input type="hidden" name="add_customer" value="1">
                  <div class="row g-2">
                    <div class="col-md-6">
                      <label class="form-label">First Name</label>
                      <input class="form-control" name="first_name" required>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Last Name</label>
                      <input class="form-control" name="last_name" required>
                    </div>
                    <div class="col-12">
                      <label class="form-label">Phone</label>
                      <input class="form-control" name="phone">
                    </div>
                    <div class="col-12">
                      <label class="form-label">Address</label>
                      <input class="form-control" name="address">
                    </div>
                    <div class="col-12">
                      <button class="btn btn-outline-dark w-100 mt-2">
                        <i class="fa-solid fa-user-plus me-1"></i> Add Customer
                      </button>
                    </div>
                  </div>
                </form>

                <hr>

                <div class="small-muted">
                  Tip: Use consistent customer names for accurate utang tracking & forecasting later.
                </div>
              </div>
            </div>
          </div>

        

                </div><!-- row -->

              </div>

            </main>

        

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

        

        <script>

        const products = <?= json_encode($products) ?>;

        

        function peso(n){

          return (Number(n||0)).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});

        }

        

        function formatISODate(y, m, d){

          const mm = String(m).padStart(2,'0');

          const dd = String(d).padStart(2,'0');

          return `${y}-${mm}-${dd}`;

        }

        

        // Client display only (server still enforces due date)

        function computeKinsenasDueDateClient(){

          const now = new Date();

          const year = now.getFullYear();

          const monthIndex = now.getMonth(); // 0-11

          const day = now.getDate();

        

          const kinsenas = [2, 15, 25, 30];

        

          // last day of current month

          const lastDay = new Date(year, monthIndex + 1, 0).getDate();

        

          for (const k of kinsenas){

            if(day <= k && k <= lastDay){

              return formatISODate(year, monthIndex + 1, k);

            }

          }

        

          // next month 2

          let ny = year, nm = monthIndex + 2; // month number 1-12

          if(nm === 13){ nm = 1; ny = year + 1; }

          return formatISODate(ny, nm, 2);

        }

        

        function toggleDueDate(){

          const t = document.getElementById('sale_type').value;

          const wrap = document.getElementById('dueDateWrap');

          wrap.classList.toggle('d-none', t !== 'utang');

        

          if(t === 'utang'){

            const due = computeKinsenasDueDateClient();

            const dueInput = document.getElementById('dueDateText');

            if(dueInput) dueInput.value = due;

          }

        }

        

        function addRow(){

          const tbody = document.querySelector('#itemsTable tbody');

          const tr = document.createElement('tr');

        

          // Product select

          const tdProd = document.createElement('td');

          const sel = document.createElement('select');

          sel.name = "product_id[]";

          sel.className = "form-select form-select-sm";

          sel.required = true;

          sel.innerHTML = `<option value="">Select product</option>` + products.map(p => {

            const label = `${p.variety} - ${p.grade} (₱${peso(p.unit_price)})`;

            return `<option value="${p.product_id}" data-price="${p.unit_price}" data-stock="${p.stock_kg}">${label}</option>`;

          }).join('');

          sel.onchange = () => fillRow(tr);

          tdProd.appendChild(sel);

        

          // Stock

          const tdStock = document.createElement('td');

          tdStock.innerHTML = `<span class="badge bg-secondary">0.00 kg</span>`;

        

          // Qty

          const tdQty = document.createElement('td');

          const qty = document.createElement('input');

          qty.type = "number";

          qty.step = "0.01";

          qty.min = "0";

          qty.name = "qty_kg[]";

          qty.className = "form-control form-control-sm";

          qty.required = true;

          qty.oninput = () => recalc(tr);

          tdQty.appendChild(qty);

        

          // Price

          const tdPrice = document.createElement('td');

          const price = document.createElement('input');

          price.type = "number";

          price.step = "0.01";

          price.min = "0";

          price.name = "unit_price[]";

          price.className = "form-control form-control-sm";

          price.required = true;

          price.oninput = () => recalc(tr);

          tdPrice.appendChild(price);

        

          // Line total

          const tdLine = document.createElement('td');

          tdLine.innerHTML = `<span class="fw-bold">₱0.00</span>`;

        

          // Remove btn

          const tdX = document.createElement('td');

          const btn = document.createElement('button');

          btn.type = "button";

          btn.className = "btn btn-sm btn-outline-danger";

          btn.innerHTML = `<i class="fa-solid fa-xmark"></i>`;

          btn.onclick = () => { tr.remove(); recalcAll(); };

          tdX.appendChild(btn);

        

          tr.appendChild(tdProd);

          tr.appendChild(tdStock);

          tr.appendChild(tdQty);

          tr.appendChild(tdPrice);

          tr.appendChild(tdLine);

          tr.appendChild(tdX);

        

          tbody.appendChild(tr);

        }

        

        function fillRow(tr){

          const sel = tr.querySelector('select');

          const opt = sel.options[sel.selectedIndex];

          const stock = Number(opt.getAttribute('data-stock') || 0);

          const prc = Number(opt.getAttribute('data-price') || 0);

        

          tr.children[1].innerHTML = `<span class="badge bg-info text-dark">${peso(stock)} kg</span>`;

          tr.querySelector('input[name="unit_price[]"]').value = prc ? prc : '';

          recalc(tr);

        }

        

        function recalc(tr){

          const qty = Number(tr.querySelector('input[name="qty_kg[]"]').value || 0);

          const prc = Number(tr.querySelector('input[name="unit_price[]"]').value || 0);

          const line = qty * prc;

          tr.children[4].innerHTML = `<span class="fw-bold">₱${peso(line)}</span>`;

          recalcAll();

        }

        

        function recalcAll(){

          let total = 0;

          document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {

            const qty = Number(tr.querySelector('input[name="qty_kg[]"]').value || 0);

            const prc = Number(tr.querySelector('input[name="unit_price[]"]').value || 0);

            total += qty * prc;

          });

          document.getElementById('grandTotal').innerText = peso(total);

        }

        

        // Start with 1 row

        addRow();

        toggleDueDate();

        </script>

        

        </body>

        </html>```
