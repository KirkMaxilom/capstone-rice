<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit;
}

$username = $_SESSION['username'] ?? 'User';
$user_id  = (int)($_SESSION['user_id'] ?? 0);

include '../config/db.php';

$error = "";

/**
 * AJAX: Add Supplier (modal)
 */
if(isset($_POST['ajax']) && $_POST['ajax'] === 'add_supplier'){
    header('Content-Type: application/json; charset=utf-8');

    $name    = trim($_POST['supplier_name'] ?? '');
    $phone   = trim($_POST['supplier_phone'] ?? '');
    $address = trim($_POST['supplier_address'] ?? '');

    if($name === ''){
        echo json_encode(['ok'=>false, 'message'=>'Supplier name is required.']);
        exit;
    }

    // avoid duplicates
    $stmt = $conn->prepare("SELECT supplier_id, name FROM suppliers WHERE name = ? LIMIT 1");
    if(!$stmt){
        echo json_encode(['ok'=>false, 'message'=>'DB error: '.$conn->error]);
        exit;
    }
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if($dup){
        echo json_encode([
            'ok'=>true,
            'supplier_id'=>(int)$dup['supplier_id'],
            'supplier_name'=>$dup['name'],
            'message'=>'Supplier already exists. Selected.'
        ]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO suppliers (name, phone, address, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
    if(!$stmt){
        echo json_encode(['ok'=>false, 'message'=>'DB error: '.$conn->error]);
        exit;
    }
    $stmt->bind_param("sss", $name, $phone, $address);
    $stmt->execute();
    $newId = (int)$conn->insert_id;
    $stmt->close();

    echo json_encode([
        'ok'=>true,
        'supplier_id'=>$newId,
        'supplier_name'=>$name,
        'message'=>'Supplier added!'
    ]);
    exit;
}

/**
 * STOCK IN (Receiving)
 */
if($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])){
    $product_id    = (int)($_POST['product_id'] ?? 0);
    $supplier_id   = (int)($_POST['supplier_id'] ?? 0);
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $due_date      = $_POST['due_date'] ?? null;
    $qty_kg        = (float)($_POST['qty_kg'] ?? 0);
    $unit_cost     = (float)($_POST['unit_cost'] ?? 0);
    $note          = trim($_POST['note'] ?? '');

    if($product_id <= 0 || $supplier_id <= 0){
        $error = "Please select a product and supplier.";
    } elseif($qty_kg <= 0){
        $error = "Please enter a valid quantity.";
    } elseif($unit_cost < 0){
        $error = "Unit cost must be 0 or higher.";
    } else {
        $total_amount = $qty_kg * $unit_cost;
        if(!$due_date && $total_amount > 0){
            $due_date = date('Y-m-d', strtotime($purchase_date . ' +7 days'));
        }

        $conn->begin_transaction();
        try {
            $purchase_status = 'received';
            $stmtP = $conn->prepare("INSERT INTO purchases (supplier_id, purchase_date, total_amount, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if(!$stmtP){ throw new Exception("Prepare failed (purchases): ".$conn->error); }
            $stmtP->bind_param("isdsi", $supplier_id, $purchase_date, $total_amount, $purchase_status, $user_id);
            $stmtP->execute();
            $purchase_id = (int)$conn->insert_id;
            $stmtP->close();

            if($total_amount > 0){
                $amount_paid = 0.00;
                $balance     = $total_amount;
                $ap_status   = 'unpaid';

                $stmtAP = $conn->prepare("INSERT INTO account_payable (purchase_id, supplier_id, total_amount, amount_paid, balance, due_date, status, approved, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())");
                if(!$stmtAP){ throw new Exception("Prepare failed (account_payable): ".$conn->error); }
                $stmtAP->bind_param("iidddss", $purchase_id, $supplier_id, $total_amount, $amount_paid, $balance, $due_date, $ap_status);
                $stmtAP->execute();
                $stmtAP->close();
            }

            $reference_type = 'purchase';
            $type = 'in';
            if($note === "") $note = "Stock received (Receiving)";

            $stmt2 = $conn->prepare("INSERT INTO inventory_transactions (product_id, qty_kg, reference_id, reference_type, type, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            if(!$stmt2){ throw new Exception("Prepare failed (inventory_transactions): ".$conn->error); }
            $stmt2->bind_param("idisss", $product_id, $qty_kg, $purchase_id, $reference_type, $type, $note);
            $stmt2->execute();
            $stmt2->close();

            $conn->commit();
            header("Location: add_stock.php?success=received");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to receive stock: " . $e->getMessage();
        }
    }
}

// Fetch products + suppliers
$products  = $conn->query("SELECT product_id, variety, grade FROM products WHERE archived=0 ORDER BY variety ASC, grade ASC");
$suppliers = $conn->query("SELECT supplier_id, name FROM suppliers WHERE status='active' ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock In (Receiving) | DOHIVES</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }
.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); transition:.3s; }
.modern-card:hover { transform:translateY(-4px); }
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
        <?= htmlspecialchars($username) ?>
      </a>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="../admin/profile.php">Profile</a></li>
        <li><a class="dropdown-item text-danger" href="../logout.php">Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- Main Content -->
<main class="main-content">

<h2 class="mb-3">Stock In (Receiving)</h2>

<?php if($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if(isset($_GET['success']) && $_GET['success'] === 'received'): ?>
  <div class="alert alert-success">
    Stock received successfully! Purchase created.
    <br><small class="text-muted">
      Note: Accounts Payable is created only when Unit Cost is greater than 0.
    </small>
  </div>
<?php endif; ?>

<div class="card modern-card mt-3">
  <div class="card-body">
    <form method="POST">

        <div class="mb-3">
            <label class="form-label">Product</label>
            <select name="product_id" class="form-select" required>
                <option value="">Select product</option>
                <?php while($row = $products->fetch_assoc()): ?>
                    <option value="<?= (int)$row['product_id'] ?>">
                      <?= htmlspecialchars($row['variety'] . " - " . $row['grade']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label d-flex justify-content-between align-items-center">
              <span>Supplier</span>
              <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                + Add Supplier
              </button>
            </label>

            <select id="supplierSelect" name="supplier_id" class="form-select" required>
                <option value="">Select supplier</option>
                <?php while($s = $suppliers->fetch_assoc()): ?>
                    <option value="<?= (int)$s['supplier_id'] ?>">
                      <?= htmlspecialchars($s['name']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
            <div class="form-text">Select an existing supplier or add a new one.</div>
        </div>

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Receiving Date</label>
            <input type="date" name="purchase_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Due Date</label>
            <input type="date" name="due_date" class="form-control" value="">
            <div class="form-text">Optional. If blank, default is +7 days (only when Unit Cost > 0).</div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Quantity (kg)</label>
            <input type="number" step="0.01" min="0.01" name="qty_kg" class="form-control" required>
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-md-4">
            <label class="form-label">Unit Cost (₱/kg)</label>
            <input type="number" step="0.01" min="0" name="unit_cost" class="form-control" value="0" required>
            <div class="form-text">If 0, no Accounts Payable will be created.</div>
          </div>

          <div class="col-md-8">
            <label class="form-label">Note</label>
            <input type="text" name="note" class="form-control" placeholder="Example: Delivered by supplier / received by staff">
          </div>
        </div>

        <button type="submit" class="btn btn-primary mt-3">
          <i class="fas fa-arrow-down"></i> Receive Stock
        </button>
    </form>
  </div>
</div>

</main>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Supplier</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div id="supplierModalAlert" class="alert d-none"></div>

        <div class="mb-3">
          <label class="form-label">Supplier Name *</label>
          <input type="text" id="modalSupplierName" class="form-control" placeholder="e.g. ABC Rice Trading">
        </div>

        <div class="mb-3">
          <label class="form-label">Phone (optional)</label>
          <input type="text" id="modalSupplierPhone" class="form-control" placeholder="e.g. 09123456789">
        </div>

        <div class="mb-3">
          <label class="form-label">Address (optional)</label>
          <textarea id="modalSupplierAddress" class="form-control" rows="2" placeholder="e.g. CDO"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="saveSupplierBtn" class="btn btn-primary">Save Supplier</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const supplierModalAlert = document.getElementById('supplierModalAlert');

function showModalAlert(type, msg){
  supplierModalAlert.className = 'alert alert-' + type;
  supplierModalAlert.textContent = msg;
  supplierModalAlert.classList.remove('d-none');
}

document.getElementById('saveSupplierBtn').addEventListener('click', async () => {
  supplierModalAlert.classList.add('d-none');

  const name = document.getElementById('modalSupplierName').value.trim();
  const phone = document.getElementById('modalSupplierPhone').value.trim();
  const address = document.getElementById('modalSupplierAddress').value.trim();

  if(!name){
    showModalAlert('danger', 'Supplier name is required.');
    return;
  }

  const formData = new FormData();
  formData.append('ajax', 'add_supplier');
  formData.append('supplier_name', name);
  formData.append('supplier_phone', phone);
  formData.append('supplier_address', address);

  try{
    const res = await fetch('add_stock.php', { method: 'POST', body: formData });
    const data = await res.json();

    if(!data.ok){
      showModalAlert('danger', data.message || 'Failed to add supplier.');
      return;
    }

    const select = document.getElementById('supplierSelect');
    let opt = select.querySelector('option[value="' + data.supplier_id + '"]');
    if(!opt){
      opt = document.createElement('option');
      opt.value = data.supplier_id;
      opt.textContent = data.supplier_name;
      select.appendChild(opt);
    }
    select.value = data.supplier_id;

    showModalAlert('success', data.message || 'Supplier added!');

    setTimeout(() => {
      const modalEl = document.getElementById('addSupplierModal');
      const modal = bootstrap.Modal.getInstance(modalEl);
      modal.hide();

      document.getElementById('modalSupplierName').value = '';
      document.getElementById('modalSupplierPhone').value = '';
      document.getElementById('modalSupplierAddress').value = '';
      supplierModalAlert.classList.add('d-none');
    }, 600);

  } catch(err){
    showModalAlert('danger', 'Network/Server error. Please try again.');
  }
});
</script>
</body>
</html>