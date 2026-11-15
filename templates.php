<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}
$planEntitlements = getPlanEntitlements($db, (int) $_SESSION['user_id']);

$templates = [
    'classic' => [
        'name' => 'Classic',
        'description' => 'A balanced, familiar layout for everyday client work.',
        'number' => 'INV-2048',
        'client' => 'Avery Morgan',
        'item' => 'Brand strategy',
        'amount' => '1,250.00',
    ],
    'modern' => [
        'name' => 'Modern',
        'description' => 'A confident, high-contrast design for standout proposals.',
        'number' => 'INV-2049',
        'client' => 'Jordan Lee',
        'item' => 'Digital product design',
        'amount' => '2,400.00',
    ],
    'minimal' => [
        'name' => 'Minimal',
        'description' => 'A quiet, pared-back invoice that keeps the focus on the work.',
        'number' => 'INV-2050',
        'client' => 'Taylor Reed',
        'item' => 'Creative direction',
        'amount' => '980.00',
    ],
    'executive' => [
        'name' => 'Executive',
        'description' => 'A polished, high-trust look for consulting and corporate work.',
        'number' => 'INV-2051',
        'client' => 'Morgan Ellis',
        'item' => 'Business consulting',
        'amount' => '3,200.00',
    ],
    'creative' => [
        'name' => 'Creative',
        'description' => 'A vivid, structured design for agencies and independent studios.',
        'number' => 'INV-2052',
        'client' => 'Cameron Blake',
        'item' => 'Campaign identity',
        'amount' => '1,850.00',
    ],
    'editorial' => [
        'name' => 'Editorial',
        'description' => 'A typography-led style for thoughtful, detail-rich services.',
        'number' => 'INV-2053',
        'client' => 'Riley Bennett',
        'item' => 'Editorial direction',
        'amount' => '1,460.00',
    ],
    'ledger' => [
        'name' => 'Ledger',
        'description' => 'A structured finance layout in deep green.',
        'number' => 'INV-2054',
        'client' => 'Casey Bennett',
        'item' => 'Financial advisory',
        'amount' => '2,750.00',
    ],
    'atelier' => [
        'name' => 'Atelier',
        'description' => 'A warm, expressive invoice for independent studios.',
        'number' => 'INV-2055',
        'client' => 'Robin Ellis',
        'item' => 'Visual identity',
        'amount' => '1,980.00',
    ],
    'horizon' => [
        'name' => 'Horizon',
        'description' => 'A crisp blue layout with a modern asymmetric grid.',
        'number' => 'INV-2056',
        'client' => 'Alex Rivera',
        'item' => 'Product development',
        'amount' => '4,200.00',
    ],
    'studio' => [
        'name' => 'Studio',
        'description' => 'A bold, graphic layout for independent teams.',
        'number' => 'INV-2057',
        'client' => 'Jamie Park',
        'item' => 'Creative production',
        'amount' => '2,150.00',
    ],
    'cobalt' => [
        'name' => 'Cobalt',
        'description' => 'A confident blue sidebar anchors a crisp, modern invoice.',
        'number' => 'INV-2058',
        'client' => 'Skylar Brooks',
        'item' => 'Product strategy',
        'amount' => '2,850.00',
    ],
    'terra' => [
        'name' => 'Terra',
        'description' => 'Warm clay accents bring a grounded feel to service work.',
        'number' => 'INV-2059',
        'client' => 'Emery Quinn',
        'item' => 'Interior styling',
        'amount' => '1,740.00',
    ],
    'noir' => [
        'name' => 'Noir',
        'description' => 'A dramatic charcoal header pairs with a bright, clean page.',
        'number' => 'INV-2060',
        'client' => 'Rowan Ellis',
        'item' => 'Creative direction',
        'amount' => '3,600.00',
    ],
    'botanical' => [
        'name' => 'Botanical',
        'description' => 'Soft greens and rounded details create a calm, natural look.',
        'number' => 'INV-2061',
        'client' => 'Sage Morgan',
        'item' => 'Sustainability review',
        'amount' => '1,920.00',
    ],
    'aurora' => [
        'name' => 'Aurora',
        'description' => 'A luminous violet-blue header gives digital work a bright edge.',
        'number' => 'INV-2062',
        'client' => 'Ari Bennett',
        'item' => 'Software implementation',
        'amount' => '4,800.00',
    ],
    'blueprint' => [
        'name' => 'Blueprint',
        'description' => 'Precise rules and measured spacing suit technical projects.',
        'number' => 'INV-2063',
        'client' => 'Jules Parker',
        'item' => 'Systems architecture',
        'amount' => '3,250.00',
    ],
    'sunrise' => [
        'name' => 'Sunrise',
        'description' => 'A bright amber accent adds warmth without distracting from totals.',
        'number' => 'INV-2064',
        'client' => 'Taylor James',
        'item' => 'Campaign planning',
        'amount' => '1,680.00',
    ],
    'monochrome' => [
        'name' => 'Monochrome',
        'description' => 'Strong black-and-white hierarchy keeps every detail clear.',
        'number' => 'INV-2065',
        'client' => 'Reese Cameron',
        'item' => 'Editorial services',
        'amount' => '1,440.00',
    ],
    'coastal' => [
        'name' => 'Coastal',
        'description' => 'Sea-glass teal and open spacing make a relaxed, polished page.',
        'number' => 'INV-2066',
        'client' => 'Finley Hayes',
        'item' => 'Brand photography',
        'amount' => '2,320.00',
    ],
    'gilded' => [
        'name' => 'Gilded',
        'description' => 'Midnight ink and restrained gold detail elevate premium work.',
        'number' => 'INV-2067',
        'client' => 'Drew Sinclair',
        'item' => 'Executive advisory',
        'amount' => '5,400.00',
    ],
];
$businessName = $business['name'] ?: 'Your business';
$businessInitial = strtoupper(substr(trim((string) $businessName), 0, 1));
$hasBusinessLogo = resolveBusinessLogoPath($business['logo_path'] ?? null, (int) $business['id']) !== null;

$pageTitle = 'Choose an invoice design';
$siteTitle = 'Choose an invoice design | Invoices Generators';
$activeNav = 'templates.php';
include __DIR__ . '/includes/header.php';
?>

<div class="app-page templates-library">
    <section class="card templates-library-intro">
        <span class="eyebrow">NEW INVOICE</span>
        <h3>Choose your invoice design</h3>
        <p>Choose a layout, then edit the client, line items, dates and details before creating your invoice.</p>
    </section>

    <section class="workspace-template-grid" aria-label="Ready-made invoice templates">
        <?php foreach ($templates as $templateKey => $template): ?>
            <article class="card workspace-template-card">
                <div class="workspace-template-preview">
                    <div class="invoice-document template-<?= e($templateKey); ?>">
                        <header class="invoice-document-header">
                            <div class="invoice-issuer">
                                <span class="invoice-logo<?= $hasBusinessLogo ? ' has-image' : ''; ?>">
                                    <span <?= $hasBusinessLogo ? 'hidden' : ''; ?>><?= e($businessInitial ?: 'I'); ?></span>
                                    <?php if ($hasBusinessLogo): ?><img src="logo.php?business=1" alt="<?= e($businessName); ?> logo"><?php endif; ?>
                                </span>
                                <span>
                                    <strong><?= e($businessName); ?></strong>
                                    <small><?= e($business['email'] ?: 'Your business email'); ?></small>
                                </span>
                            </div>
                            <div class="invoice-heading">
                                <span class="invoice-kicker">PAYMENT REQUEST</span>
                                <h1>Invoice</h1>
                                <strong><?= e($template['number']); ?></strong>
                            </div>
                        </header>
                        <div class="invoice-parties">
                            <section>
                                <span class="invoice-label">FROM</span>
                                <strong><?= e($businessName); ?></strong>
                                <span>Your business details</span>
                            </section>
                            <section>
                                <span class="invoice-label">BILL TO</span>
                                <strong><?= e($template['client']); ?></strong>
                                <span>Client details</span>
                            </section>
                            <section class="invoice-dates">
                                <div><span class="invoice-label">ISSUED</span><strong>Nov 02, 2026</strong></div>
                                <div><span class="invoice-label">DUE</span><strong>Nov 16, 2026</strong></div>
                                <div><span class="invoice-label">STATUS</span><strong class="invoice-status">Draft</strong></div>
                            </section>
                        </div>
                        <div class="invoice-items-wrap">
                            <table class="invoice-items">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>Qty</th>
                                        <th class="invoice-number-cell">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong><?= e($template['item']); ?></strong></td>
                                        <td>1</td>
                                        <td class="invoice-number-cell">USD <?= e($template['amount']); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="invoice-summary-row">
                            <div class="invoice-message">
                                <span class="invoice-label">A NOTE</span>
                                <p>Thank you for your business.</p>
                            </div>
                            <div class="invoice-totals">
                                <div><span>Subtotal</span><strong>USD <?= e($template['amount']); ?></strong></div>
                                <div class="invoice-grand-total"><span>Total due</span><strong>USD <?= e($template['amount']); ?></strong></div>
                            </div>
                        </div>
                        <footer class="invoice-document-footer">
                            <span><?= e($businessName); ?></span>
                            <span>Invoice preview</span>
                        </footer>
                    </div>
                </div>
                <div class="workspace-template-card-content">
                    <div>
                        <span class="workspace-template-kicker">INVOICE DESIGN</span>
                        <h4><?= e($template['name']); ?></h4>
                        <p><?= e($template['description']); ?></p>
                    </div>
                    <?php if (in_array($templateKey, $planEntitlements['plan']['templates'], true)): ?>
                        <a class="btn btn-primary" href="invoice_create.php?template=<?= e($templateKey); ?>&amp;visual=1">Use this design</a>
                    <?php else: ?>
                        <a class="btn btn-secondary" href="billing.php">Pro / Business design · View plans</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
