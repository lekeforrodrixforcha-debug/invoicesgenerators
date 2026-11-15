<?php
require_once __DIR__ . '/app/init.php';

if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $country = trim((string) ($_POST['country'] ?? ''));

        if ($name === '' || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $password === '' || $country === '' || strlen($country) > 100) {
            throw new InvalidArgumentException('Please complete all required fields, including your country.');
        }

        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new InvalidArgumentException('Password must be between 8 and 72 characters long.');
        }

        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            throw new InvalidArgumentException('An account with this email already exists.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->beginTransaction();
        $db->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)')->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => $hash,
        ]);

        $userId = (int) $db->lastInsertId();
        provisionFreeSubscription($db, $userId);
        $db->prepare('INSERT INTO businesses (user_id, name, email, city, country, currency) VALUES (:user_id, :name, :email, :city, :country, :currency)')->execute([
            'user_id' => $userId,
            'name' => $name . ' Studio',
            'email' => $email,
            'city' => 'Your city',
            'country' => $country,
            'currency' => 'USD',
        ]);
        $db->commit();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        redirect('dashboard.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof InvalidArgumentException) {
            $errorMessage = $e->getMessage();
        } else {
            error_log('Registration failed: ' . $e->getMessage());
            $errorMessage = 'Your account could not be created. Please try again.';
        }
    }
}

$pageTitle = 'Create account';
$siteTitle = 'Create account | Invoices Generators';
include __DIR__ . '/includes/header.php';
?>

<div class="auth-shell">
    <div class="auth-card">
        <span class="eyebrow">Create account</span>
        <h2>Set up your business workspace</h2>
        <p>Start creating branded invoices and tracking payments professionally.</p>

        <?php if (!empty($errorMessage)): ?>
            <div class="notice notice-error"><?= e($errorMessage); ?></div>
        <?php endif; ?>

        <form method="post" action="register.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label for="name">Full name</label>
                    <input id="name" type="text" name="name" placeholder="Jane Doe" required>
                </div>
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" placeholder="jane@company.com" required>
                </div>
                <div class="form-group">
                    <label for="country">Country</label>
                    <input id="country" type="text" name="country" placeholder="Enter country" autocomplete="off" required>
                    <small>Your country determines available subscription payment methods. The workspace default currency is USD.</small>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-input-wrap">
                    <input id="password" type="password" name="password" placeholder="At least 8 characters" autocomplete="new-password" required>
                    <button class="password-visibility-toggle" type="button" data-password-toggle aria-controls="password" aria-label="Show password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>
                        <span data-password-toggle-label>Show</span>
                    </button>
                </div>
            </div>

            <button class="btn btn-primary" type="submit">Create account</button>
        </form>

        <div class="link-line">
            Already have an account? <a href="login.php">Log in</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
