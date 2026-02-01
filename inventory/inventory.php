<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit;
}

$username = $_SESSION['username'] ?? 'User';
$user_id  = (int)($_SESSION['user_id'] ?? 0);

include '../config/db.php';

/**
 * IMPORTANT:
 * Your DB enums are LOWERCASE:
 * inventory_transactions.reference_type: sale,purchase,return,delivery,adjust
 * inventory_transactions.type: in,out,adjust
 *
 * So we will use lowercase consistently.
 */

// --- Handle Approve Return ---
if(isset($_POST['approve_return'])) {
    $return_id = (int)($_POST['return_id'] ?? 0);

    // Get return details
    $stmt = $conn->prepare("SELECT return_id, sale_id, product_id, qty_returned, status FROM returns WHERE return_id = ?");
    $stmt->bind_param("i", $return_id);
    $stmt->execute();
    $ret = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$ret){
        $message = "Return not found.";
    } else {
        $product_id    = (int)$ret['product_id'];
        $qty_returned  = (float)$ret['qty_returned'];

        // Use transaction so stock update + log always match
        $conn->begin_transaction();
        try {
            // Update return status (keep your enum style; your table uses 'pending/approved/rejected')
            $stmt = $conn->prepare("UPDATE returns SET status='approved' WHERE return_id=?");
            $stmt->bind_param("i", $return_id);
            $stmt->execute();
            $stmt->close();

            // Update stock
            $stmt = $conn->prepare("UPDATE products SET stock_kg = stock_kg + ? WHERE product_id = ?");
            $stmt->bind_param("di", $qty_returned, $product_id);
            $stmt->execute();
            $stmt->close();

            // Log inventory transaction
            $reference_type = "return";
            $type = "in";
            $note = "Customer return approved";

            $stmt = $conn->prepare("
                INSERT INTO inventory_transactions
                    (product_id, qty_kg, reference_id, reference_type, type, note, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("idisss", $product_id, $qty_returned, $return_id, $reference_type, $type, $note);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $message = "Return approved and stock updated!";
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Failed to approve return: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory Logs | DE ORO HIYS</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }

.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); transition:.3s; }
.main-content { padding-top:70px; padding-left: 20px; padding-right: 20px; }

.table td, .table th { padding:0.5rem; vertical-align: middle; }

.badge-return { background-color:#198754; }
.badge-sale { background-color:#dc3545; }
.badge-purchase { background-color:#0d6efd; }
.badge-adjust { background-color:#fd7e14; }

.type-in { color:#198754; font-weight:700; }
.type-out { color:#dc3545; font-weight:700; }
.type-adjust { color:#fd7e14; font-weight:700; }
</style>
</head>
<body class="with-sidebar">

<?php include '../includes/sidebar.php'; ?>

<!-- TOP NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top" style="margin-left: 260px; width: calc(100% - 260px); z-index: 1020;">
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

<!-- MAIN CONTENT -->
<main class="main-content">

<h3 class="mb-4">Inventory Timeline</h3>

<?php if(isset($message)): ?>
<div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="card modern-card shadow-sm">
<div class="card-body table-responsive">
<table class="table table-striped table-bordered align-middle">
<thead class="table-dark">
<tr>
  <th>Date</th>
  <th>Product</th>
  <th>Customer</th>
  <th>Supplier</th>
  <th>Qty</th>
  <th>Type</th>
  <th>Reference</th>
  <th>Note</th>
  <th>Action</th>
</tr>
</thead>
<tbody>
<?php
$sql = "
SELECT 
    it.*,
    p.variety,
    p.grade,

    r.return_id,
    r.status AS return_status,

    sa.sale_id,
    c.first_name,
    c.last_name,

    pu.purchases_id,
    sup.name AS supplier_name

FROM inventory_transactions it
LEFT JOIN products p ON it.product_id = p.product_id

LEFT JOIN returns r
    ON it.reference_type='return' AND it.reference_id = r.return_id

LEFT JOIN sales sa
    ON it.reference_type='sale' AND it.reference_id = sa.sale_id
LEFT JOIN customers c
    ON sa.customer_id = c.customer_id

LEFT JOIN purchases pu
    ON it.reference_type='purchase' AND it.reference_id = pu.purchases_id
LEFT JOIN suppliers sup
    ON pu.supplier_id = sup.supplier_id

ORDER BY it.created_at DESC
";

$result = $conn->query($sql);
if(!$result){
    die("Query Error: " . $conn->error);
}

while($row = $result->fetch_assoc()){
    $date = date("M d, Y h:i A", strtotime($row['created_at']));
    $product = trim(($row['variety'] ?? '')." - ".($row['grade'] ?? ''));
    if($product === "-") $product = "N/A";

    $customer = (!empty($row['first_name'])) ? ($row['first_name']." ".$row['last_name']) : "N/A";

    $type = strtolower(trim($row['type'] ?? ''));
    $isIn = ($type === 'in');

    // ✅ define $ref FIRST so no undefined warning
    $ref = strtoupper(trim($row['reference_type'] ?? ''));

    // qty sign rules (adjust uses its own sign)
    $qtyNum = (float)($row['qty_kg'] ?? 0);
    if($type === 'adjust'){
        $qty = ($qtyNum >= 0 ? '+' : '-') . number_format(abs($qtyNum), 2);
    } else {
        $qty = ($isIn ? '+' : '-') . number_format(abs($qtyNum), 2);
    }

    $typeText = strtoupper($type);
    if($type === 'adjust') $typeClass = "type-adjust";
    else $typeClass = $isIn ? "type-in" : "type-out";

    // Supplier only for PURCHASE + IN
    $supplier = (!empty($row['supplier_name'])) ? $row['supplier_name'] : 'N/A';
    $showSupplier = ($isIn && $ref === "PURCHASE") ? $supplier : "N/A";

    // Reference badge
    $refBadge = "";
    if($ref === "RETURN") $refBadge = "<span class='badge badge-return'>RETURN #".(int)$row['reference_id']."</span>";
    else if($ref === "SALE") $refBadge = "<span class='badge badge-sale'>SALE #".(int)$row['reference_id']."</span>";
    else if($ref === "PURCHASE") $refBadge = "<span class='badge badge-purchase'>PURCHASE #".(int)$row['reference_id']."</span>";
    else if($ref === "ADJUST") $refBadge = "<span class='badge badge-adjust'>ADJUST</span>";
    else $refBadge = "<span class='badge bg-secondary'>".$ref."</span>";

    $note = htmlspecialchars($row['note'] ?? '');

    echo "<tr>
        <td>{$date}</td>
        <td>".htmlspecialchars($product)."</td>
        <td>".htmlspecialchars($customer)."</td>
        <td>".htmlspecialchars($showSupplier)."</td>
        <td>{$qty}</td>
        <td class='{$typeClass}'>{$typeText}</td>
        <td>{$refBadge}</td>
        <td>{$note}</td>
        <td>";

    // Show approve button only if it's a RETURN and still pending
    // (your returns.status values are 'pending/approved/rejected')
    if($ref === "RETURN" && isset($row['return_id']) && strtolower($row['return_status'] ?? '') === 'pending'){
        echo "<form method='POST' class='m-0'>
                <input type='hidden' name='return_id' value='".(int)$row['return_id']."'>
                <button type='submit' name='approve_return' class='btn btn-sm btn-success'>Approve</button>
              </form>";
    } else {
        echo "-";
    }

    echo "</td></tr>";
}
?>
</tbody>
</table>
</div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
