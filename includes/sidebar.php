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
    'inventory'     => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4',
    'sales'         => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
    'users'         => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    'analytics'     => 'M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941',
    'settings'      => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    'logout'        => 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75',
    'finance'       => 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    'chevron-down'  => 'M19.5 8.25l-7.5 7.5-7.5-7.5',
    'customers'     => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    'returns'       => 'M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3',
    'logs'          => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'
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
            <a href="<?= BASE_URL ?>inventory/inventory.php" class="sidebar-nav-item ps-5 <?= $current_page == 'inventory.php' ? 'active' : '' ?>">
                <span class="sidebar-nav-text">Inventory Logs</span>
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

        <a href="<?= BASE_URL ?>admin/analytics.php" class="sidebar-nav-item <?= $current_page == 'analytics.php' ? 'active' : '' ?>">
            <?= render_svg('analytics', $svg_paths) ?>
            <span class="sidebar-nav-text">Analytics</span>
        </a>

        <a href="<?= BASE_URL ?>admin/system_logs.php" class="sidebar-nav-item <?= $current_page == 'system_logs.php' ? 'active' : '' ?>">
            <?= render_svg('logs', $svg_paths) ?>
            <span class="sidebar-nav-text">System Logs</span>
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
        <a href="<?= BASE_URL ?>cashier/customers.php" class="sidebar-nav-item <?= $current_page == 'customers.php' ? 'active' : '' ?>">
            <?= render_svg('customers', $svg_paths) ?>
            <span class="sidebar-nav-text">Customers</span>
        </a>
        <a href="<?= BASE_URL ?>cashier/inventory_view.php" class="sidebar-nav-item <?= $current_page == 'inventory_view.php' ? 'active' : '' ?>">
            <?= render_svg('inventory', $svg_paths) ?>
            <span class="sidebar-nav-text">Inventory</span>
        </a>
        <a href="<?= BASE_URL ?>cashier/payments.php" class="sidebar-nav-item <?= $current_page == 'payments.php' ? 'active' : '' ?>">
            <?= render_svg('finance', $svg_paths) ?>
            <span class="sidebar-nav-text">Payments</span>
        </a>
        <a href="<?= BASE_URL ?>cashier/returns.php" class="sidebar-nav-item <?= $current_page == 'returns.php' ? 'active' : '' ?>">
            <?= render_svg('returns', $svg_paths) ?>
            <span class="sidebar-nav-text">Returns</span>
        </a>
    <?php endif; ?>

    <!-- OWNER -->
    <?php if($isOwner): ?>
        <a href="<?= BASE_URL ?>owner/inventory_monitoring.php" class="sidebar-nav-item <?= $current_page == 'inventory_monitoring.php' ? 'active' : '' ?>">
            <?= render_svg('inventory', $svg_paths) ?>
            <span class="sidebar-nav-text">Inventory Monitoring</span>
        </a>

        <a href="<?= BASE_URL ?>owner/sales_report.php" class="sidebar-nav-item <?= $current_page == 'sales_report.php' ? 'active' : '' ?>">
            <?= render_svg('sales', $svg_paths) ?>
            <span class="sidebar-nav-text">Sales Reports</span>
        </a>

        <!-- Finance Dropdown -->
        <?php $isFinance = in_array($current_page, ['supplier_payables.php', 'payables.php']); ?>
        <a class="sidebar-nav-item <?= $isFinance ? 'active' : '' ?>" data-bs-toggle="collapse" href="#financeMenu" role="button" aria-expanded="<?= $isFinance ? 'true' : 'false' ?>" aria-controls="financeMenu">
            <?= render_svg('finance', $svg_paths) ?>
            <span class="sidebar-nav-text">Finance</span>
            <div class="sidebar-nav-icon ms-auto">
                 <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </div>
        </a>
        <div class="collapse <?= $isFinance ? 'show' : '' ?>" id="financeMenu">
            <a href="<?= BASE_URL ?>owner/supplier_payables.php" class="sidebar-nav-item ps-5 <?= ($current_page == 'supplier_payables.php' || $current_page == 'payables.php') ? 'active' : '' ?>">
                <span class="sidebar-nav-text">Supplier Payables (AP)</span>
            </a>
        </div>

        <a href="<?= BASE_URL ?>owner/returns_report.php" class="sidebar-nav-item <?= $current_page == 'returns_report.php' ? 'active' : '' ?>">
            <?= render_svg('returns', $svg_paths) ?>
            <span class="sidebar-nav-text">Returns Report</span>
        </a>

        <a href="<?= BASE_URL ?>owner/analytics.php" class="sidebar-nav-item <?= $current_page == 'analytics.php' ? 'active' : '' ?>">
            <?= render_svg('analytics', $svg_paths) ?>
            <span class="sidebar-nav-text">Analytics</span>
        </a>

        <a href="<?= BASE_URL ?>owner/system_logs.php" class="sidebar-nav-item <?= $current_page == 'system_logs.php' ? 'active' : '' ?>">
            <?= render_svg('logs', $svg_paths) ?>
            <span class="sidebar-nav-text">System Logs</span>
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