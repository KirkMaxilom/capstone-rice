<?php
session_start();
include_once __DIR__ . '/../config/db.php';

// Check user role and redirect if not authorized
function authorize_user($allowed_roles) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "login.php");
        exit;
    }

    $role = strtolower($_SESSION['role'] ?? '');
    if (!in_array($role, $allowed_roles)) {
        header("Location: " . BASE_URL . "login.php");
        exit;
    }
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Helper function to prevent XSS
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$username = $_SESSION['username'] ?? 'User';
$user_role = strtolower($_SESSION['role'] ?? '');
$profile_link = BASE_URL . $user_role . '/profile.php';

if ($user_role === 'cashier') {
    $profile_link = BASE_URL . 'cashier/cashier_profile.php';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>De Oro Kalinga</title>
    
    <!-- Bootstrap and Font Awesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    
    <!-- Sidebar and custom styles -->
    <link rel="stylesheet" href="<?= BASE_URL ?>css/sidebar.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/dashboard.css">
    
    <style>
        body {
            background-color: #f4f6f9;
        }
        .main-content {
            margin-left: 260px;
            padding: 20px;
            padding-top: 80px; /* Adjusted for fixed navbar */
        }
        .navbar-brand {
            font-weight: bold;
        }
        .navbar {
            z-index: 1021; /* Ensure navbar is above sidebar */
        }
    </style>
</head>
<body class="with-sidebar">

<?php include_once __DIR__ . '/sidebar.php'; ?>

<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top" style="margin-left: 260px; width: calc(100% - 260px);">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">DE ORO HIYS GENERAL MERCHANDISE</a>
        <div class="ms-auto dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fa-solid fa-user-circle me-1"></i>
                <?= h($username) ?> <small class="text-muted">(<?= h(ucfirst($user_role)) ?>)</small>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                <li><a class="dropdown-item" href="<?= $profile_link ?>"><i class="fa-solid fa-user me-2"></i>Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<main class="main-content">
    <!-- Content goes here -->