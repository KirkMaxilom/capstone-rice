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
$user_id = (int)$_SESSION['user_id'];
include '../config/db.php';

$success = "";
$error = "";

/* =========================
   HANDLE ACTIONS
========================= */
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    
    // 1. APPROVE AP
    if(isset($_POST['approve_ap'])){
        $ap_id = (int)$_POST['ap_id'];
        $stmt = $conn->prepare("UPDATE account_payable SET approved=1, approved_by=?, approved_at=NOW() WHERE ap_id=?");
        $stmt->bind_param("ii", $user_id, $ap_id);
        if($stmt->execute()){
            $success = "Payable approved successfully.";
        } else {
            $error = "Failed to approve.";
        }
        $stmt->close();
    }

    // 2. RECORD PAYMENT
    if(isset($_POST['pay_ap'])){
        $ap_id  = (int)$_POST['ap_id'];
        $amount = (float)$_POST['amount'];
        $method = $_POST['method'] ?? 'cash';
        $ref    = trim($_POST['reference_no'] ?? '');
        $note   = trim($_POST['note'] ?? '');

        // Fetch current balance
        $chk = $conn->query("SELECT balance, purchase_id, supplier_id FROM account_payable WHERE ap_id=$ap_id");
        $row = $chk->fetch_assoc();

        if($row){
            if($amount <= 0 || $amount > $row['balance']){
                $error = "Invalid payment amount. Balance is ₱".number_format($row['balance'],2);
            } else {
                $conn->begin_transaction();
                try {
                    // Insert Payment
                    $stmt = $conn->prepare("INSERT INTO supplier_payments (ap_id, purchase_id, supplier_id, amount, method, reference_no, paid_by, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->bind_param("iiidssis", $ap_id, $row['purchase_id'], $row['supplier_id'], $amount, $method, $ref, $user_id, $note);
                    $stmt->execute();
                    $stmt->close();

                    // Update AP Balance
                    $newBal = $row['balance'] - $amount;
                    $status = ($newBal <= 0.01) ? 'paid' : 'partial';
                    
                    $stmt = $conn->prepare("UPDATE account_payable SET amount_paid = amount_paid + ?, balance = ?, status = ? WHERE ap_id = ?");
                    $stmt->bind_param("ddsi", $amount, $newBal, $status, $ap_id);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    $success = "Payment recorded successfully.";
                } catch(Exception $e){
                    $conn->rollback();
                    $error = "Error recording payment: ".$e->getMessage();
                }
            }
        }
    }
}

/* =========================
   FETCH PAYABLES
========================= */
$filter = $_GET['status'] ?? 'unpaid';
$where = "WHERE 1=1";

if($filter === 'unpaid'){
    $where .= " AND ap.balance > 0";
} elseif($filter === 'paid'){
    $where .= " AND ap.balance <= 0";
}

$sql = "
SELECT ap.*, s.name AS supplier_name, p.purchase_date
FROM account_payable ap
JOIN suppliers s ON ap.supplier_id = s.supplier_id
LEFT JOIN purchases p ON ap.purchase_id = p.purchases_id
$where
ORDER BY ap.due_date ASC, ap.ap_id DESC
";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Supplier Payables | DOHIVES</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }
.main-content { padding-top:70px; padding-left: 20px; padding-right: 20px; }
.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); }
.table td, .table th { vertical-align: middle; }
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
        <li><a class="dropdown-item" href="profile.php">Profile</a></li>
        <li><a class="dropdown-item text-danger" href="../logout.php">Logout</a></li>
      </ul>
    </div>
  </div>
</nav>

<!-- MAIN CONTENT -->
<main class="main-content">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="fw-bold mb-0">Supplier Payables (AP)</h3>
        <p class="text-muted small mb-0">Manage debts to suppliers from Stock In transactions.</p>
    </div>
    <div class="btn-group">
        <a href="?status=unpaid" class="btn btn-outline-dark <?= $filter==='unpaid'?'active':'' ?>">Unpaid / Partial</a>
        <a href="?status=paid" class="btn btn-outline-dark <?= $filter==='paid'?'active':'' ?>">Paid History</a>
        <a href="?status=all" class="btn btn-outline-dark <?= $filter==='all'?'active':'' ?>">All</a>
    </div>
</div>

<?php if($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<div class="card modern-card">
<div class="card-body table-responsive">
    <table class="table table-striped table-hover">
        <thead class="table-dark">
            <tr>
                <th>AP ID</th>
                <th>Supplier</th>
                <th>Purchase Date</th>
                <th>Due Date</th>
                <th>Total</th>
                <th>Balance</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if($result && $result->num_rows > 0): ?>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td>#<?= $row['ap_id'] ?></td>
                <td class="fw-bold"><?= htmlspecialchars($row['supplier_name']) ?></td>
                <td><?= date('M d, Y', strtotime($row['purchase_date'])) ?></td>
                <td>
                    <?php 
                        $due = strtotime($row['due_date']);
                        $isOverdue = ($due < time() && $row['balance'] > 0);
                        echo "<span class='".($isOverdue ? 'text-danger fw-bold':'')."'>".date('M d, Y', $due)."</span>";
                    ?>
                </td>
                <td>₱<?= number_format($row['total_amount'], 2) ?></td>
                <td class="fw-bold text-danger">₱<?= number_format($row['balance'], 2) ?></td>
                <td>
                    <?php if(!$row['approved']): ?>
                        <span class="badge bg-warning text-dark">Pending Approval</span>
                    <?php elseif($row['balance'] <= 0): ?>
                        <span class="badge bg-success">PAID</span>
                    <?php else: ?>
                        <span class="badge bg-primary"><?= strtoupper($row['status']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if(!$row['approved']): ?>
                        <form method="POST" onsubmit="return confirm('Approve this payable?');">
                            <input type="hidden" name="ap_id" value="<?= $row['ap_id'] ?>">
                            <button type="submit" name="approve_ap" class="btn btn-sm btn-success">
                                <i class="fas fa-check"></i> Approve
                            </button>
                        </form>
                    <?php elseif($row['balance'] > 0): ?>
                        <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#payModal<?= $row['ap_id'] ?>">
                            <i class="fas fa-money-bill-wave"></i> Pay
                        </button>
                        
                        <!-- PAY MODAL -->
                        <div class="modal fade" id="payModal<?= $row['ap_id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Record Payment (AP #<?= $row['ap_id'] ?>)</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="ap_id" value="<?= $row['ap_id'] ?>">
                                            <div class="mb-3">
                                                <label>Amount (Max: ₱<?= number_format($row['balance'],2) ?>)</label>
                                                <input type="number" name="amount" step="0.01" max="<?= $row['balance'] ?>" class="form-control" required>
                                            </div>
                                            <div class="mb-3">
                                                <label>Method</label>
                                                <select name="method" class="form-select">
                                                    <option value="cash">Cash</option>
                                                    <option value="bank">Bank Transfer</option>
                                                    <option value="gcash">GCash</option>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label>Reference No. (Optional)</label>
                                                <input type="text" name="reference_no" class="form-control">
                                            </div>
                                            <div class="mb-3">
                                                <label>Note</label>
                                                <input type="text" name="note" class="form-control">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="submit" name="pay_ap" class="btn btn-primary">Confirm Payment</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <span class="text-muted"><i class="fas fa-check-circle"></i> Done</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="8" class="text-center text-muted py-4">No payables found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>