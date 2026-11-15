<?php
$user = $currentLayoutUser ?? getCurrentUser($db);
$business = $isAdminUser ? null : getCurrentBusiness($db);
$activeAnnouncements = [];
if (!$isAdminUser) {
    $announcementQuery = $db->prepare('SELECT title, message FROM announcements WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= :now) AND (ends_at IS NULL OR ends_at >= :now) ORDER BY created_at DESC, id DESC LIMIT 3');
    $announcementQuery->execute(['now' => gmdate('Y-m-d H:i:s')]);
    $activeAnnouncements = $announcementQuery->fetchAll();
}
?>
<header class="topbar">
    <div>
        <p class="eyebrow"><?= $isAdminUser ? 'Platform administration' : 'Workspace'; ?></p>
        <h2><?= e($pageTitle ?? 'Dashboard'); ?></h2>
    </div>
    <div class="topbar-actions">
        <?php if (!$isAdminUser && ($pageTitle ?? '') !== 'Choose an invoice design' && ($pageTitle ?? '') !== 'Create invoice'): ?>
            <a class="btn btn-secondary" href="templates.php">Create invoice</a>
        <?php endif; ?>
        <div class="user-badge">
            <div class="avatar"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?></div>
            <div>
                <strong><?= e($user['name'] ?? 'User'); ?></strong>
                <small><?= $isAdminUser ? 'Administrator' : e($business['name'] ?? 'Business'); ?></small>
            </div>
        </div>
    </div>
</header>
<?php if ($activeAnnouncements): ?>
    <aside class="user-announcements" aria-label="Platform announcements">
        <?php foreach ($activeAnnouncements as $announcement): ?>
            <article><strong><?= e($announcement['title']); ?></strong><p><?= e($announcement['message']); ?></p></article>
        <?php endforeach; ?>
    </aside>
<?php endif; ?>
