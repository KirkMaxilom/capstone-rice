<?php
session_start();
if(!isset($_SESSION['user_id'])){
    header("Location: ../login.php");
    exit;
}
if(strtolower($_SESSION['role'] ?? '') !== 'admin'){
    header("Location: ../login.php");
    exit;
}

$username = $_SESSION['username'] ?? 'Admin';
include '../config/db.php';

$success = $_GET['success'] ?? '';
$error   = $_GET['error'] ?? '';

// Toggle status (activate/deactivate)
if(isset($_GET['toggle']) && isset($_GET['id'])){
    $id = (int)$_GET['id'];

    // prevent admin disabling self
    if($id === (int)$_SESSION['user_id']){
        header("Location: users.php?error=" . urlencode("You cannot disable your own account."));
        exit;
    }

    $res = $conn->query("SELECT status FROM users WHERE user_id=$id LIMIT 1");
    if($res && $row = $res->fetch_assoc()){
        $newStatus = (strtolower($row['status']) === 'active') ? 'inactive' : 'active';
        $conn->query("UPDATE users SET status='$newStatus' WHERE user_id=$id");

        // activity log
        $admin_id = (int)$_SESSION['user_id'];
        $desc = "Changed user_id $id status to $newStatus";
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, activity_type, description, created_at) VALUES (?, 'USER_STATUS', ?, NOW())");
        $log->bind_param("is", $admin_id, $desc);
        $log->execute();
        $log->close();
    }

    header("Location: users.php?success=" . urlencode("User status updated."));
    exit;
}

// Fetch users
$users = $conn->query("SELECT user_id, username, first_name, last_name, phone, role, status, created_at FROM users ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>User Management | DOHIVES</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="../css/sidebar.css">

<style>
body { background:#f4f6f9; }
.modern-card { border-radius:14px; box-shadow:0 6px 16px rgba(0,0,0,.12); transition:.3s; }
.modern-card:hover { transform:translateY(-4px); }
.bg-gradient-primary {background:linear-gradient(135deg,#1d2671,#c33764);}
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
      <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown"><?= htmlspecialchars($username) ?></a>
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
    <h3 class="fw-bold mb-0">User Management</h3>
    <small class="text-muted">Admin adds Cashier and Owner accounts</small>
  </div>
  <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#addUserModal">
    <i class="fas fa-user-plus me-1"></i> Add User
  </button>
</div>

<?php if($success): ?>
  <div class="alert alert-success py-2"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if($error): ?>
  <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card modern-card">
  <div class="card-body table-responsive">
    <table class="table table-striped table-hover align-middle">
      <thead class="table-dark">
        <tr>
          <th>ID</th>
          <th>Username</th>
          <th>Name</th>
          <th>Phone</th>
          <th>Role</th>
          <th>Status</th>
          <th>Created</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if($users && $users->num_rows>0): ?>
        <?php while($u = $users->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$u['user_id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars(trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? ''))) ?></td>
            <td><?= htmlspecialchars($u['phone'] ?? '') ?></td>
            <td><span class="badge bg-primary"><?= htmlspecialchars($u['role']) ?></span></td>
            <td>
              <?php if(strtolower($u['status'])==='active'): ?>
                <span class="badge bg-success">active</span>
              <?php else: ?>
                <span class="badge bg-secondary">inactive</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($u['created_at']) ?></td>
            <td>
              <?php if((int)$u['user_id'] === (int)$_SESSION['user_id']): ?>
                <span class="text-muted">—</span>
              <?php else: ?>
                <a class="btn btn-sm btn-outline-dark"
                   href="users.php?toggle=1&id=<?= (int)$u['user_id'] ?>"
                   onclick="return confirm('Change this user status?')">
                   user status
                </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
      <?php else: ?>
        <tr><td colspan="8" class="text-center text-muted">No users found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</main>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="add_user.php">
        <div class="modal-header">
          <h5 class="modal-title">Add User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label">Username</label>
            <input class="form-control" name="username" required>
          </div>

          <div class="mb-2">
            <label class="form-label">Password</label>
            <input class="form-control" type="password" name="password" required>
          </div>

          <div class="row">
            <div class="col-md-6 mb-2">
              <label class="form-label">First Name</label>
              <input class="form-control" name="first_name">
            </div>
            <div class="col-md-6 mb-2">
              <label class="form-label">Last Name</label>
              <input class="form-control" name="last_name">
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label">Phone</label>
            <input class="form-control" name="phone">
          </div>

          <div class="row">
            <div class="col-md-6 mb-2">
              <label class="form-label">Role</label>
              <select class="form-select" name="role" required>
                <option value="cashier">Cashier</option>
                <option value="owner">Owner</option>
              </select>
            </div>
            <div class="col-md-6 mb-2">
              <label class="form-label">Status</label>
              <select class="form-select" name="status">
                <option value="active" selected>Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>

          <small class="text-muted">
            Note: Admin accounts are not created here for security.
          </small>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-dark">Create User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>