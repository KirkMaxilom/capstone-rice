<?php
// Get current page and role
// NOTE: BASE_URL is defined in config/db.php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';
$isAdmin   = ($role === 'admin');
$isOwner   = ($role === 'owner');
$isCashier = ($role === 'cashier');

// Define SVG paths for icons
$svg_paths = [
    'home'          => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    'inventory'     => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    'sales'         => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    'users'         => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    'analytics'     => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
    'settings'      => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    'logout'        => 'M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1',
    'finance'       => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    'chevron-down'  => 'M19 9l-7 7-7-7'
];

function render_svg($icon, $svg_paths) {
    $svg = $svg_paths[$icon] ?? '';
    return <<<HTML
    <div class="sidebar-nav-icon">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{$svg}"/>
        </svg>
    </div>
    HTML;
}

$dashboard_link = BASE_URL . $role . '/dashboard.php';
$profile_link = BASE_URL . $role . '/profile.php';
if ($role === 'cashier') {
    $profile_link = BASE_URL . 'cashier/cashier_profile.php';
}

?>

<div class="sidebar-component">
    <div class="sidebar-logo">
        <div class="sidebar-logo-icon"></div>
    </div>
    
    <!-- Dashboard -->
    <a href="<?= $dashboard_link ?>" class="sidebar-nav-item <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
        <?= render_svg('home', $svg_paths) ?>
        <span class="sidebar-nav-text">Dashboard</span>
    </a>

    <!-- ADMIN -->
    <?php if($isAdmin): ?>
        <!-- Inventory (collapsible) -->
        <a class="sidebar-nav-item" data-bs-toggle="collapse" href="#inventoryMenu" role="button" aria-expanded="false" aria-controls="inventoryMenu">
            <?= render_svg('inventory', $svg_paths) ?>
            <span class="sidebar-nav-text">Inventory</span>
            <div class="sidebar-nav-icon ms-auto">
                 <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </div>
        </a>
        <div class="collapse" id="inventoryMenu">
            <a href="<?= BASE_URL ?>admin/products.php" class="sidebar-nav-item ps-5 <?= $current_page == 'products.php' ? 'active' : '' ?>">
                <span class="sidebar-nav-text">Products</span>
            </a>
            <a href="<?= BASE_URL ?>inventory/add_stock.php" class="sidebar-nav-item ps-5 <?= $current_page == 'add_stock.php' ? 'active' : '' ?>">
                <span class="sidebar-nav-text">Stock In</span>
            </a>
             <a href="<?= BASE_URL ?>inventory/adjust_stock.php" class="sidebar-nav-item ps-5 <?= $current_page == 'adjust_stock.php' ? 'active' : '' ?>">
                <span class="sidebar-nav-text">Adjust Stock</span>
            </a>
        </div>

        <a href="<?= BASE_URL ?>admin/sales.php" class="sidebar-nav-item <?= $current_page == 'sales.php' ? 'active' : '' ?>">
            <?= render_svg('sales', $svg_paths) ?>
            <span class="sidebar-nav-text">Sales</span>
        </a>

        <a href="<?= BASE_URL ?>admin/users.php" class="sidebar-nav-item <?= $current_page == 'users.php' ? 'active' : '' ?>">
            <?= render_svg('users', $svg_paths) ?>
            <span class="sidebar-nav-text">Users</span>
        </a>
    <?php endif; ?>

    <!-- CASHIER -->
    <?php if($isCashier): ?>
        <a href="<?= BASE_URL ?>cashier/pos.php" class="sidebar-nav-item <?= $current_page == 'pos.php' ? 'active' : '' ?>">
            <?= render_svg('finance', $svg_paths) ?>
            <span class="sidebar-nav-text">POS</span>
        </a>
        <a href="<?= BASE_URL ?>cashier/sales_history.php" class="sidebar-nav-item <?= $current_page == 'sales_history.php' ? 'active' : '' ?>">
            <?= render_svg('sales', $svg_paths) ?>
            <span class="sidebar-nav-text">Sales History</span>
        </a>
    <?php endif; ?>

    <!-- OWNER -->
    <?php if($isOwner): ?>
        <a href="<?= BASE_URL ?>owner/analytics.php" class="sidebar-nav-item <?= $current_page == 'analytics.php' ? 'active' : '' ?>">
            <?= render_svg('analytics', $svg_paths) ?>
            <span class="sidebar-nav-text">Analytics</span>
        </a>
        <a href="<?= BASE_URL ?>owner/inventory_monitoring.php" class="sidebar-nav-item <?= $current_page == 'inventory_monitoring.php' ? 'active' : '' ?>">
            <?= render_svg('inventory', $svg_paths) ?>
            <span class="sidebar-nav-text">Inventory</span>
        </a>
        <a href="<?= BASE_URL ?>owner/sales_report.php" class="sidebar-nav-item <?= $current_page == 'sales_report.php' ? 'active' : '' ?>">
            <?= render_svg('sales', $svg_paths) ?>
            <span class="sidebar-nav-text">Sales Report</span>
        </a>
    <?php endif; ?>

    <div class="sidebar-spacer"></div>

    <!-- COMMON -->
    <a href="<?= $profile_link ?>" class="sidebar-nav-item <?= ($current_page == 'profile.php' || $current_page == 'cashier_profile.php') ? 'active' : '' ?>">
        <?= render_svg('settings', $svg_paths) ?>
        <span class="sidebar-nav-text">Profile</span>
    </a>
    <a href="<?= BASE_URL ?>logout.php" class="sidebar-nav-item">
        <?= render_svg('logout', $svg_paths) ?>
        <span class="sidebar-nav-text">Logout</span>
    </a>
</div>