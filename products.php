<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $sku = trim((string) ($_POST['sku'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $price = (float) ($_POST['price'] ?? 0);
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? $business['currency'])));
        $taxRate = (float) ($_POST['tax_rate'] ?? 0);

        if ($name === '') {
            throw new InvalidArgumentException('Product name is required.');
        }

        assertPlanLimit($db, (int) $_SESSION['user_id'], (int) $business['id'], 'products');
        $stmt = $db->prepare('INSERT INTO products (business_id, name, description, sku, unit, price, currency, tax_rate) VALUES (:business_id, :name, :description, :sku, :unit, :price, :currency, :tax_rate)');
        $stmt->execute([
            'business_id' => $business['id'],
            'name' => $name,
            'description' => $description,
            'sku' => $sku,
            'unit' => $unit,
            'price' => $price,
            'currency' => $currency,
            'tax_rate' => $taxRate,
        ]);

        setFlash('success', 'Product saved successfully.');
        redirect('products.php');
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$stmt = $db->prepare('SELECT * FROM products WHERE business_id = :business_id ORDER BY created_at DESC');
$stmt->execute(['business_id' => $business['id']]);
$products = $stmt->fetchAll();

$pageTitle = 'Products & services';
$siteTitle = 'Products | Invoices Generators';
$activeNav = 'products.php';
include __DIR__ . '/includes/header.php';

$successMessage = getFlash('success');
?>

<div class="app-page">
    <?php if (!empty($successMessage)): ?>
        <div class="notice notice-success"><?= e($successMessage); ?></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="notice notice-error"><?= e($errorMessage); ?></div>
    <?php endif; ?>

    <section class="card">
        <div class="card-head">
            <h3>Add product or service</h3>
        </div>

        <form method="post" action="products.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input id="name" type="text" name="name" placeholder="Website design" required>
                </div>
                <div class="form-group">
                    <label for="sku">SKU</label>
                    <input id="sku" type="text" name="sku" placeholder="WEB-001">
                </div>
                <div class="form-group">
                    <label for="unit">Unit</label>
                    <input id="unit" type="text" name="unit" placeholder="hour / project / item">
                </div>
                <div class="form-group">
                    <label for="price">Default price</label>
                    <input id="price" type="number" step="0.01" min="0" name="price" value="0">
                </div>
                <div class="form-group">
                    <label for="currency">Currency</label>
                    <select id="currency" name="currency">
                        <option value="USD" <?= $business['currency'] === 'USD' ? 'selected' : ''; ?>>USD</option>
                        <option value="EUR">EUR</option>
                        <option value="GHS">GHS</option>
                        <option value="GBP">GBP</option>
                        <option value="XAF" <?= $business['currency'] === 'XAF' ? 'selected' : ''; ?>>XAF (FCFA)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tax_rate">Tax rate (%)</label>
                    <input id="tax_rate" type="number" step="0.01" min="0" name="tax_rate" value="0">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Describe the service or deliverable"></textarea>
                </div>
            </div>

            <div class="inline-actions">
                <button class="btn btn-primary" type="submit">Save product</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h3>Catalogue</h3>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>SKU</th>
                        <th>Unit</th>
                        <th>Price</th>
                        <th>Tax</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?= e($product['name']); ?></td>
                            <td><?= e($product['sku'] ?: '—'); ?></td>
                            <td><?= e($product['unit'] ?: '—'); ?></td>
                            <td><?= e(formatMoney((float) $product['price'], $product['currency'] ?? 'USD')); ?></td>
                            <td><?= e((string) $product['tax_rate']); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
