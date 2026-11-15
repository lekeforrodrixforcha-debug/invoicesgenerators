<?php
require_once __DIR__ . '/app/init.php';

if (!empty($_SESSION['user_id'])) {
    $currentUser = getCurrentUser($db);
    redirect(($currentUser['role'] ?? 'user') === 'admin' ? 'admin.php' : 'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new InvalidArgumentException('Please enter both email and password.');
        }

        $emailHash = hash('sha256', strtolower($email));
        $db->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff')->execute(['cutoff' => time() - 86400]);
        $attemptCount = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = :email_hash AND attempted_at >= :cutoff');
        $attemptCount->execute(['email_hash' => $emailHash, 'cutoff' => time() - 900]);
        if ((int) $attemptCount->fetchColumn() >= 10) {
            throw new InvalidArgumentException('Too many sign-in attempts. Wait 15 minutes and try again.');
        }

        $stmt = $db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $db->prepare('INSERT INTO login_attempts (email_hash, attempted_at) VALUES (:email_hash, :attempted_at)')
                ->execute(['email_hash' => $emailHash, 'attempted_at' => time()]);
            throw new InvalidArgumentException('Invalid email or password.');
        }

        $db->prepare('DELETE FROM login_attempts WHERE email_hash = :email_hash')->execute(['email_hash' => $emailHash]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['last_activity_update'] = 0;
        redirect(($user['role'] ?? 'user') === 'admin' ? 'admin.php' : 'dashboard.php');
    } catch (Throwable $e) {
        if ($e instanceof InvalidArgumentException) {
            $errorMessage = $e->getMessage();
        } else {
            error_log('Login failed: ' . $e->getMessage());
            $errorMessage = 'Sign-in could not be completed. Please try again.';
        }
    }
}

$pageTitle = 'Login';
$siteTitle = 'Login | Invoices Generators';
include __DIR__ . '/includes/header.php';
?>

<div class="auth-shell">
    <div class="auth-card">
        <span class="eyebrow">Welcome back</span>
        <h2>Sign in to your workspace</h2>
        <p>Access invoices, customers, products and reports.</p>

        <?php if (!empty($errorMessage)): ?>
            <div class="notice notice-error"><?= e($errorMessage); ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <div class="form-group">
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" placeholder="name@company.com" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-input-wrap">
                    <input id="password" type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
                    <button class="password-visibility-toggle" type="button" data-password-toggle aria-controls="password" aria-label="Show password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>
                        <span data-password-toggle-label>Show</span>
                    </button>
                </div>
            </div>

            <button class="btn btn-primary" type="submit">Log in</button>
        </form>

        <div class="link-line">
            Need an account? <a href="register.php">Create one</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
