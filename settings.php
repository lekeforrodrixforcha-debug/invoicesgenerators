<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
$user = getCurrentUser($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        if (isset($_POST['business_submit'])) {
            $name = trim((string) ($_POST['business_name'] ?? ''));
            $email = trim((string) ($_POST['business_email'] ?? ''));
            $phone = trim((string) ($_POST['business_phone'] ?? ''));
            $address = trim((string) ($_POST['business_address'] ?? ''));
            $city = trim((string) ($_POST['business_city'] ?? ''));
            $country = trim((string) ($_POST['business_country'] ?? ''));
            $currency = 'USD';

            if ($name === '') {
                throw new InvalidArgumentException('Business name is required.');
            }

            $stmt = $db->prepare('UPDATE businesses SET name = :name, email = :email, phone = :phone, address = :address, city = :city, country = :country, currency = :currency WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'city' => $city,
                'country' => $country,
                'currency' => $currency,
                'id' => $business['id'],
            ]);

            setFlash('profile_success', 'Business profile updated.');
        }

        if (isset($_POST['password_submit'])) {
            $newPassword = (string) ($_POST['new_password'] ?? '');
            if (strlen($newPassword) < 8) {
                throw new InvalidArgumentException('Password must be at least 8 characters long.');
            }

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id')->execute([
                'password_hash' => $hash,
                'id' => $user['id'],
            ]);

            setFlash('password_success', 'Password updated successfully.');
        }

        redirect('settings.php');
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$pageTitle = 'Settings';
$siteTitle = 'Settings | Invoices Generators';
$activeNav = 'settings.php';
include __DIR__ . '/includes/header.php';

$profileSuccess = getFlash('profile_success');
$passwordSuccess = getFlash('password_success');
?>

<div class="app-page">
    <?php if (!empty($errorMessage)): ?>
        <div class="notice notice-error"><?= e($errorMessage); ?></div>
    <?php endif; ?>

    <?php if (!empty($profileSuccess)): ?>
        <div class="notice notice-success"><?= e($profileSuccess); ?></div>
    <?php endif; ?>

    <?php if (!empty($passwordSuccess)): ?>
        <div class="notice notice-success"><?= e($passwordSuccess); ?></div>
    <?php endif; ?>

    <section class="card">
        <div class="card-head">
            <h3>Business profile</h3>
        </div>

        <form method="post" action="settings.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <input type="hidden" name="business_submit" value="1">
            <div class="form-grid">
                <div class="form-group">
                    <label for="business_name">Business name</label>
                    <input id="business_name" type="text" name="business_name" value="<?= e($business['name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="business_email">Business email</label>
                    <input id="business_email" type="email" name="business_email" value="<?= e($business['email'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="business_phone">Phone</label>
                    <input id="business_phone" type="text" name="business_phone" value="<?= e($business['phone'] ?? ''); ?>">
                </div>
                <div class="form-group"><label>Workspace currency</label><p class="muted-copy">USD for workspace defaults. Plan payments use FCFA in Cameroon.</p></div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="business_address">Address</label>
                    <textarea id="business_address" name="business_address"><?= e($business['address'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="business_city">City</label>
                    <input id="business_city" type="text" name="business_city" value="<?= e($business['city'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="business_country">Country</label>
                    <input id="business_country" type="text" name="business_country" value="<?= e($business['country'] ?? ''); ?>" list="supported-countries" autocomplete="country-name">
                    <datalist id="supported-countries"><option value="Cameroon"><option value="Cameroun"><option value="United States"><option value="United Kingdom"><option value="France"><option value="Canada"><option value="Ghana"><option value="Nigeria"></datalist>
                </div>
            </div>

            <div class="inline-actions">
                <button class="btn btn-primary" type="submit">Save settings</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h3>Security</h3>
        </div>

        <form method="post" action="settings.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <input type="hidden" name="password_submit" value="1">
            <div class="form-group">
                <label for="new_password">New password</label>
                <input id="new_password" type="password" name="new_password" placeholder="Minimum 8 characters">
            </div>
            <div class="inline-actions">
                <button class="btn btn-secondary" type="submit">Update password</button>
            </div>
        </form>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
