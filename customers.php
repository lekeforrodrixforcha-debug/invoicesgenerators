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
        $company = trim((string) ($_POST['company'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Customer name is required.');
        }

        assertPlanLimit($db, (int) $_SESSION['user_id'], (int) $business['id'], 'customers');
        $stmt = $db->prepare('INSERT INTO customers (business_id, name, company, email, phone, address, country, notes) VALUES (:business_id, :name, :company, :email, :phone, :address, :country, :notes)');
        $stmt->execute([
            'business_id' => $business['id'],
            'name' => $name,
            'company' => $company,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'country' => $country,
            'notes' => $notes,
        ]);

        setFlash('success', 'Customer saved successfully.');
        redirect('customers.php');
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$stmt = $db->prepare('SELECT * FROM customers WHERE business_id = :business_id ORDER BY created_at DESC');
$stmt->execute(['business_id' => $business['id']]);
$customers = $stmt->fetchAll();

$pageTitle = 'Customers';
$siteTitle = 'Customers | Invoices Generators';
$activeNav = 'customers.php';
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
            <h3>Add customer</h3>
        </div>

        <form method="post" action="customers.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="name">Full name</label>
                    <input id="name" type="text" name="name" placeholder="Jane Smith" required>
                </div>
                <div class="form-group">
                    <label for="company">Company</label>
                    <input id="company" type="text" name="company" placeholder="Company name">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" placeholder="jane@company.com">
                </div>
                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input id="phone" type="text" name="phone" placeholder="+1 555 000 0000">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="address">Billing address</label>
                    <textarea id="address" name="address" placeholder="Street, city, country"></textarea>
                </div>
                <div class="form-group">
                    <label for="country">Country</label>
                    <input id="country" type="text" name="country" placeholder="United States">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" placeholder="Preferred billing terms, requirements, or reminders"></textarea>
                </div>
            </div>

            <div class="inline-actions">
                <button class="btn btn-primary" type="submit">Save customer</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h3>Customer list</h3>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Email</th>
                        <th>Country</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td><?= e($customer['name']); ?></td>
                            <td><?= e($customer['company'] ?: '—'); ?></td>
                            <td><?= e($customer['email'] ?: '—'); ?></td>
                            <td><?= e($customer['country'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
