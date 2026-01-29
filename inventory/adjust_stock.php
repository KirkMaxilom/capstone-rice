<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit;
}

// Ensure the user has the correct role
if($_SESSION['role'] !== 'admin'){
    header("Location: ../error/403.php"); // Redirect to an unauthorized page
    exit;
}

$username = $_SESSION['username'] ?? 'User';
include '../config/db.php';

$error = "";
$success = "";

// Handle form submission (Adjust Stock)
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $product_id = (int)($_POST['product_id'] ?? 0);
    $qty_input  = (float)($_POST['qty_kg'] ?? 0); // can be + or -
    $note       = trim($_POST['note'] ?? '');

    if($product_id <= 0 || $qty_input == 0){
        $error = "Please select a product and enter a non-zero quantity.";
    } else {
        // Determine direction and absolute qty for logging
        $type = ($qty_input > 0) ? "IN" : "OUT";
        $qty_kg = abs($qty_input);

        if($note === ""){
            $note = "Manual stock adjustment";
        }

        $conn->begin_transaction();
        try {
            // Prevent negative stock on OUT
            if($type === "OUT"){
                $check = $conn->prepare("SELECT stock_kg FROM products WHERE product_id=?");
                $check->bind_param("i", $product_id);
                $check->execute();
                $current = $check->get_result()->fetch_assoc();
                $check->close();

                if(!$current){
                    throw new Exception("Product not found.");
                }
                if((float)$current['stock_kg'] < $qty_kg){
                    throw new Exception("Insufficient stock. Current stock: ".$current['stock_kg']." kg");
                }
            }

            // 1) Update products.stock_kg
            if($type === "IN"){
                $stmt = $conn->prepare("UPDATE products SET stock_kg = stock_kg + ? WHERE product_id = ?");
                $stmt->bind_param("di", $qty_kg, $product_id);
            } else {
                $stmt = $conn->prepare("UPDATE products SET stock_kg = stock_kg - ? WHERE product_id = ?");
                $stmt->bind_param("di", $qty_kg, $product_id);
            }
            $stmt->execute();
            $stmt->close();

            // 2) Log inventory transaction
            $reference_type = "ADJUSTMENT";
            $stmt2 = $conn->prepare("INSERT INTO inventory_transactions
                (product_id, qty_kg, reference_id, reference_type, type, note, created_at)
                VALUES (?, ?, NULL, ?, ?, ?, NOW())");
            $stmt2->bind_param("idsss", $product_id, $qty_kg, $reference_type, $type, $note);
            $stmt2->execute();
            $stmt2->close();

            $conn->commit();
            header("Location: adjust_stock.php?success=adjusted");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

// Fetch products
$products = $conn->query("SELECT product_id, variety, grade FROM products WHERE archived=0 ORDER BY variety ASC, grade ASC");

// Summary cards data (inventory movements)
$summary = $conn->query("
SELECT
    IFNULL(SUM(CASE WHEN type='IN' THEN qty_kg END),0) AS total_in,
    IFNULL(SUM(CASE WHEN type='OUT' THEN qty_kg END),0) AS total_out,
    IFNULL(SUM(CASE WHEN reference_type='ADJUSTMENT' AND type='IN' THEN qty_kg END),0) AS total_adjust_in,
    IFNULL(SUM(CASE WHEN reference_type='ADJUSTMENT' AND type='OUT' THEN qty_kg END),0) AS total_adjust_out
FROM inventory_transactions
")->fetch_assoc();

// Current stock should come from products.stock_kg (source of truth)
$currentStockRow = $conn->query("SELECT IFNULL(SUM(stock_kg),0) AS current_stock FROM products WHERE archived=0")->fetch_assoc();
$current_stock = (float)$currentStockRow['current_stock'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Adjust Stock | DOH-VYES</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }

/* Page-specific styles */
.main-content { padding-top: 20px; }

.modern-card {
    border-radius:14px;
    box-shadow:0 6px 16px rgba(0,0,0,.12);
    transition:.3s;
    background:#fff;
}
.modern-card:hover { transform:translateY(-4px); }

.card-summary{
    border-radius:14px;
    padding:18px;
    color:#fff;
    box-shadow:0 6px 16px rgba(0,0,0,.12);
}
.card-stock{ background:linear-gradient(135deg,#1d2671,#c33764); }
.card-in{ background:linear-gradient(135deg,#11998e,#38ef7d); }
.card-out{ background:linear-gradient(135deg,#e52d27,#b31217); }
.card-adjust{ background:linear-gradient(135deg,#f7971e,#ffd200); color:#222; }

.card-summary h3{ margin:0; font-weight:800; }
.card-summary p{ margin:0; opacity:.9; }
</style>
</head>

<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<!-- MAIN CONTENT -->
<div class="main-content">
<div class="container-fluid">

    <h2 class="mb-3">Adjust Stock</h2>

    <?php if(isset($_GET['success']) && $_GET['success'] === 'adjusted'): ?>
      <div class="alert alert-success">Stock adjusted successfully!</div>
    <?php endif; ?>

    <?php if($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mt-3 mb-4 g-3">
        <div class="col-md-3">
            <div class="card-summary card-stock">
                <h3><?= number_format($current_stock,2) ?> kg</h3>
                <p>Current Stock</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-summary card-in">
                <h3><?= number_format((float)$summary['total_in'],2) ?> kg</h3>
                <p>Total In</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-summary card-out">
                <h3><?= number_format((float)$summary['total_out'],2) ?> kg</h3>
                <p>Total Out</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-summary card-adjust">
                <h3><?= number_format((float)$summary['total_adjust_in'],2) ?> / <?= number_format((float)$summary['total_adjust_out'],2) ?> kg</h3>
                <p>Adjust (IN / OUT)</p>
            </div>
        </div>
    </div>

    <!-- Form Card -->
    <div class="card modern-card">
      <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Product</label>
                <select name="product_id" class="form-select" required>
                    <option value="">Select product</option>
                    <?php while($row = $products->fetch_assoc()): ?>
                        <option value="<?= (int)$row['product_id'] ?>">
                            <?= htmlspecialchars($row['variety']." - ".$row['grade']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Quantity (+/- kg)</label>
                <input type="number" step="0.01" name="qty_kg" class="form-control" required>
                <small class="text-muted">Use positive to add, negative to subtract</small>
            </div>

            <div class="mb-3">
                <label class="form-label">Note</label>
                <textarea name="note" class="form-control" rows="3" placeholder="Example: Spoiled rice removed / Stock correction after recount"></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
              <i class="fas fa-sliders"></i> Adjust Stock
            </button>
        </form>
      </div>
    </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
