<?php
$navItems = [
    ['label' => 'Overview', 'url' => 'dashboard.php', 'icon' => '⌂'],
    ['label' => 'New Invoice', 'url' => 'templates.php', 'icon' => '＋'],
    ['label' => 'Invoices', 'url' => 'invoices.php', 'icon' => '▣'],
    ['label' => 'Customers', 'url' => 'customers.php', 'icon' => '☰'],
    ['label' => 'Products', 'url' => 'products.php', 'icon' => '◫'],
    ['label' => 'Quotes', 'url' => 'quotes.php', 'icon' => '▤'],
    ['label' => 'Expenses', 'url' => 'expenses.php', 'icon' => '−'],
    ['label' => 'Reports', 'url' => 'reports.php', 'icon' => '▥'],
    ['label' => 'Plans & billing', 'url' => 'billing.php', 'icon' => '$'],
    ['label' => 'Settings', 'url' => 'settings.php', 'icon' => '⚙'],
];
$sidebarUser = $currentLayoutUser ?? getCurrentUser($db);
$sidebarSubscription = $sidebarUser ? getUserSubscription($db, (int) $sidebarUser['id']) : null;
$isAdminSidebar = ($sidebarUser['role'] ?? 'user') === 'admin';
$adminNavigation = require __DIR__ . '/admin_navigation.php';
$active = $activeNav ?? 'dashboard';
?>
<aside class="sidebar<?= $isAdminSidebar ? ' admin-sidebar' : ''; ?>">
    <div class="brand-box">
        <div class="brand-mark">I</div>
        <div>
            <strong><?= $isAdminSidebar ? 'Admin Console' : 'Invoices Gen'; ?></strong>
            <small><?= $isAdminSidebar ? 'Platform management' : 'Business workspace'; ?></small>
        </div>
    </div>

    <button class="sidebar-menu-button" type="button" aria-expanded="false" aria-controls="workspace-navigation">
        <span class="menu-button-icon" aria-hidden="true"><i></i><i></i><i></i></span>
        <span>Menu</span>
    </button>

    <nav class="side-nav" id="workspace-navigation">
        <?php if ($isAdminSidebar): ?>
            <?php foreach ($adminNavigation as $item): ?>
                <?php if ($item['available']): ?>
                    <?php $adminTarget = !empty($adminSidebarMode) ? $item['target'] : 'admin.php' . $item['target']; ?>
                    <a class="nav-item<?= !empty($adminSidebarMode) && $item['label'] === 'Dashboard' ? ' active' : ''; ?>" href="<?= e($adminTarget); ?>">
                        <span class="nav-icon" aria-hidden="true"><?= $item['label'] === 'Dashboard' ? '⌂' : '›'; ?></span>
                        <span><?= e($item['label']); ?></span>
                    </a>
                <?php else: ?>
                    <span class="nav-item admin-nav-disabled" aria-disabled="true" title="This module is not available yet">
                        <span class="nav-icon" aria-hidden="true">·</span>
                        <span><?= e($item['label']); ?><small>Not available yet</small></span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($navItems as $item): ?>
                <?php $itemActive = ($active === $item['url']) ? 'active' : ''; ?>
                <a class="nav-item <?= $itemActive; ?>" href="<?= $item['url']; ?>">
                    <span class="nav-icon"><?= $item['icon']; ?></span>
                    <span><?= $item['label']; ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a class="nav-item" href="logout.php">
            <span class="nav-icon" aria-hidden="true">↪</span>
            <span>Log out</span>
        </a>
        <?php if (!$isAdminSidebar): ?>
            <div class="mini-card">
                <small>Current access</small>
                <strong><?= e($sidebarSubscription['plan_name'] ?? 'Prototype'); ?></strong>
                <small>View limits and billing</small>
            </div>
        <?php endif; ?>
    </div>
</aside>
