<?php
require_once __DIR__ . '/app/init.php';
$adminUser = requireAdmin($db);
$now = gmdate('Y-m-d H:i:s');
$activeCutoff = gmdate('Y-m-d H:i:s', time() - (30 * 86400));
$dateCutoff = substr($activeCutoff, 0, 10);
$errorMessage = null;
$successMessage = getFlash('admin_success');
$sectionTarget = '#dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        $audit = $db->prepare('INSERT INTO admin_audit_log (user_id, action) VALUES (:user_id, :action)');
        $auditAction = '';

        if ($action === 'update_user_role') {
            $targetUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $role = (string) ($_POST['role'] ?? '');
            if ($targetUserId === false || $targetUserId === null || $targetUserId < 1 || !in_array($role, ['user', 'admin'], true)) {
                throw new InvalidArgumentException('Choose a valid account and role.');
            }
            if ($targetUserId === (int) $adminUser['id'] && $role !== 'admin') {
                throw new InvalidArgumentException('You cannot remove your own administrator access.');
            }
            $targetUser = $db->prepare('SELECT id, role FROM users WHERE id = :id');
            $targetUser->execute(['id' => $targetUserId]);
            $target = $targetUser->fetch();
            if (!$target) {
                throw new InvalidArgumentException('That user account no longer exists.');
            }
            if ($target['role'] === 'admin' && $role === 'user'
                && (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
                throw new InvalidArgumentException('The last administrator cannot be demoted.');
            }
            $db->prepare('UPDATE users SET role = :role WHERE id = :id')->execute(['role' => $role, 'id' => $targetUserId]);
            $auditAction = 'user_role_changed:' . $targetUserId . ':' . $role;
            $sectionTarget = '#users';
            $successText = 'Account role updated.';
        } elseif ($action === 'reset_subscription') {
            $targetUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($targetUserId === false || $targetUserId === null || $targetUserId < 1) {
                throw new InvalidArgumentException('Choose a valid account.');
            }
            $statement = $db->prepare('SELECT id, plan_id FROM subscriptions WHERE user_id = :user_id');
            $statement->execute(['user_id' => $targetUserId]);
            $subscription = $statement->fetch();
            if (!$subscription) {
                throw new InvalidArgumentException('That account has no subscription to update.');
            }
            $pendingPayment = $db->prepare("SELECT id FROM subscription_transactions WHERE subscription_id = :subscription_id AND status = 'pending' LIMIT 1");
            $pendingPayment->execute(['subscription_id' => $subscription['id']]);
            if ($pendingPayment->fetchColumn() !== false) {
                throw new InvalidArgumentException('Resolve or wait for the pending payment before resetting this subscription.');
            }
            $freePlanId = getFreePlanId($db);
            $db->beginTransaction();
            $db->prepare('UPDATE subscriptions SET plan_id = :plan_id, status = :status, current_period_start = :period_start, current_period_end = NULL, cancel_at_period_end = 0, provider = NULL, provider_customer_id = NULL, provider_subscription_id = NULL, updated_at = :updated_at WHERE id = :id')
                ->execute([
                    'plan_id' => $freePlanId,
                    'status' => 'active',
                    'period_start' => $now,
                    'updated_at' => $now,
                    'id' => $subscription['id'],
                ]);
            $db->prepare("INSERT INTO subscription_events (subscription_id, user_id, event_type, previous_plan_id, new_plan_id, details_json) VALUES (:subscription_id, :user_id, 'admin_plan_reset', :previous_plan_id, :new_plan_id, :details_json)")
                ->execute([
                    'subscription_id' => $subscription['id'],
                    'user_id' => $targetUserId,
                    'previous_plan_id' => $subscription['plan_id'],
                    'new_plan_id' => $freePlanId,
                    'details_json' => json_encode(['admin_user_id' => (int) $adminUser['id']], JSON_THROW_ON_ERROR),
                ]);
            $db->commit();
            $auditAction = 'subscription_reset_to_free:' . $targetUserId;
            $sectionTarget = '#subscriptions';
            $successText = 'Subscription access reset to Free and recorded in the subscription history.';
        } elseif ($action === 'update_plan') {
            $planId = filter_var($_POST['plan_id'] ?? null, FILTER_VALIDATE_INT);
            if ($planId === false || $planId === null || $planId < 1) {
                throw new InvalidArgumentException('Choose a valid pricing plan.');
            }
            $planLookup = $db->prepare('SELECT code FROM plans WHERE id = :id');
            $planLookup->execute(['id' => $planId]);
            $planCode = $planLookup->fetchColumn();
            if ($planCode === false) {
                throw new InvalidArgumentException('That pricing plan no longer exists.');
            }
            $prices = [];
            foreach (['monthly_price', 'yearly_price', 'monthly_price_xaf', 'yearly_price_xaf'] as $field) {
                $raw = trim((string) ($_POST[$field] ?? ''));
                if ($raw === '') {
                    $prices[$field] = null;
                    continue;
                }
                if (!is_numeric($raw) || !is_finite((float) $raw) || (float) $raw < 0 || (float) $raw > 1000000000) {
                    throw new InvalidArgumentException('Enter valid non-negative prices for each billing period.');
                }
                $prices[$field] = round((float) $raw, 2);
            }
            $available = $planCode === 'free' ? 1 : (isset($_POST['is_available']) ? 1 : 0);
            $db->prepare('UPDATE plans SET monthly_price = :monthly_price, yearly_price = :yearly_price, monthly_price_xaf = :monthly_price_xaf, yearly_price_xaf = :yearly_price_xaf, is_available = :is_available, admin_customized = 1 WHERE id = :id')
                ->execute($prices + ['is_available' => $available, 'id' => $planId]);
            $auditAction = 'pricing_plan_updated:' . $planCode;
            $sectionTarget = '#pricing-plans';
            $successText = 'Pricing plan saved. Changes apply to new checkouts.';
        } elseif ($action === 'create_promo') {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $discountType = (string) ($_POST['discount_type'] ?? '');
            $currency = strtoupper((string) ($_POST['currency'] ?? ''));
            $discountValue = $_POST['discount_value'] ?? '';
            $maxRedemptionsRaw = trim((string) ($_POST['max_redemptions'] ?? ''));
            $expiresRaw = trim((string) ($_POST['expires_at'] ?? ''));
            if ($currency === 'BOTH' && $discountType !== 'percent') {
                throw new InvalidArgumentException('A promo for both USD and XAF must use a percentage discount.');
            }
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)
                || !in_array($discountType, ['percent', 'fixed'], true)
                || !in_array($currency, ['USD', 'XAF', 'BOTH'], true)
                || !is_numeric($discountValue) || !is_finite((float) $discountValue)
                || (float) $discountValue <= 0
                || ($discountType === 'percent' && (float) $discountValue >= 100)) {
                throw new InvalidArgumentException('Enter a unique code, valid discount, and supported currency.');
            }
            $maxRedemptions = null;
            if ($maxRedemptionsRaw !== '') {
                $maxRedemptions = filter_var($maxRedemptionsRaw, FILTER_VALIDATE_INT);
                if ($maxRedemptions === false || $maxRedemptions < 1) {
                    throw new InvalidArgumentException('The redemption limit must be a positive whole number.');
                }
            }
            $expiresAt = null;
            if ($expiresRaw !== '') {
                $expiryDate = DateTimeImmutable::createFromFormat('!Y-m-d', $expiresRaw, new DateTimeZone('UTC'));
                if (!$expiryDate || $expiryDate->format('Y-m-d') !== $expiresRaw) {
                    throw new InvalidArgumentException('Enter a valid promo code expiry date.');
                }
                $expiresAt = $expiryDate->setTime(23, 59, 59)->format('Y-m-d H:i:s');
            }
            $db->prepare('INSERT INTO promo_codes (code, discount_type, discount_value, currency, max_redemptions, expires_at, created_by) VALUES (:code, :discount_type, :discount_value, :currency, :max_redemptions, :expires_at, :created_by)')
                ->execute([
                    'code' => $code,
                    'discount_type' => $discountType,
                    'discount_value' => round((float) $discountValue, 2),
                    'currency' => $currency,
                    'max_redemptions' => $maxRedemptions,
                    'expires_at' => $expiresAt,
                    'created_by' => (int) $adminUser['id'],
                ]);
            $auditAction = 'promo_code_created:' . $code;
            $sectionTarget = '#promo-codes';
            $successText = 'Promo code created and available for checkout.';
        } elseif ($action === 'toggle_promo') {
            $promoId = filter_var($_POST['promo_id'] ?? null, FILTER_VALIDATE_INT);
            if ($promoId === false || $promoId === null || $promoId < 1) {
                throw new InvalidArgumentException('Choose a valid promo code.');
            }
            $update = $db->prepare('UPDATE promo_codes SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id');
            $update->execute(['id' => $promoId]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('That promo code no longer exists.');
            }
            $auditAction = 'promo_code_toggled:' . $promoId;
            $sectionTarget = '#promo-codes';
            $successText = 'Promo code status updated.';
        } elseif ($action === 'create_announcement') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $message = trim((string) ($_POST['message'] ?? ''));
            $startsRaw = trim((string) ($_POST['starts_at'] ?? ''));
            $endsRaw = trim((string) ($_POST['ends_at'] ?? ''));
            if ($title === '' || strlen($title) > 160 || $message === '' || strlen($message) > 2000) {
                throw new InvalidArgumentException('Enter an announcement title and message (up to 2,000 characters).');
            }
            $announcementDate = static function (string $value): ?string {
                if ($value === '') {
                    return null;
                }
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
                if (!$parsed || $parsed->format('Y-m-d') !== $value) {
                    throw new InvalidArgumentException('Enter valid announcement dates.');
                }
                return $parsed->format('Y-m-d H:i:s');
            };
            $startsAt = $announcementDate($startsRaw);
            $endsAt = $announcementDate($endsRaw);
            if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
                throw new InvalidArgumentException('The announcement end date must be on or after its start date.');
            }
            $db->prepare('INSERT INTO announcements (title, message, starts_at, ends_at, created_by) VALUES (:title, :message, :starts_at, :ends_at, :created_by)')
                ->execute([
                    'title' => $title,
                    'message' => $message,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt === null ? null : substr($endsAt, 0, 10) . ' 23:59:59',
                    'created_by' => (int) $adminUser['id'],
                ]);
            $auditAction = 'announcement_created:' . substr($title, 0, 80);
            $sectionTarget = '#announcements';
            $successText = 'Announcement published and visible to signed-in non-admin users.';
        } elseif ($action === 'toggle_announcement') {
            $announcementId = filter_var($_POST['announcement_id'] ?? null, FILTER_VALIDATE_INT);
            if ($announcementId === false || $announcementId === null || $announcementId < 1) {
                throw new InvalidArgumentException('Choose a valid announcement.');
            }
            $update = $db->prepare('UPDATE announcements SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = :id');
            $update->execute(['id' => $announcementId]);
            if ($update->rowCount() !== 1) {
                throw new InvalidArgumentException('That announcement no longer exists.');
            }
            $auditAction = 'announcement_toggled:' . $announcementId;
            $sectionTarget = '#announcements';
            $successText = 'Announcement status updated.';
        } else {
            throw new InvalidArgumentException('Choose a valid admin action.');
        }

        $audit->execute(['user_id' => (int) $adminUser['id'], 'action' => $auditAction]);
        if ($db->inTransaction()) {
            $db->commit();
        }
        setFlash('admin_success', $successText);
        header('Location: admin.php' . $sectionTarget, true, 303);
        exit;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception instanceof InvalidArgumentException) {
            $errorMessage = $exception->getMessage();
        } else {
            error_log('Admin action failed for admin ' . (int) $adminUser['id'] . ': ' . $exception->getMessage());
            $errorMessage = 'The admin action could not be completed. No changes were saved.';
        }
    }
}

$db->prepare("INSERT INTO admin_audit_log (user_id, action) VALUES (:user_id, 'view_admin_dashboard')")
    ->execute(['user_id' => (int) $adminUser['id']]);
$userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeUserStmt = $db->prepare('SELECT COUNT(*) FROM users WHERE last_activity_at >= :active_cutoff');
$activeUserStmt->execute(['active_cutoff' => $activeCutoff]);
$activeUserCount = (int) $activeUserStmt->fetchColumn();
$invoiceCount = (int) $db->query('SELECT COUNT(*) FROM invoices')->fetchColumn();

$paidSubscriptionStmt = $db->prepare("
    SELECT COUNT(*)
    FROM subscriptions s
    JOIN plans p ON p.id = s.plan_id
    WHERE p.code <> 'free'
      AND s.status = 'active'
      AND (s.current_period_end IS NULL OR s.current_period_end > :now)
");
$paidSubscriptionStmt->execute(['now' => $now]);
$paidSubscriptionCount = (int) $paidSubscriptionStmt->fetchColumn();

$subscriptionRevenueStmt = $db->prepare("
    SELECT currency, COUNT(*) AS payment_count, COALESCE(SUM(amount), 0) AS total_amount
    FROM subscription_transactions
    WHERE status = 'paid' AND paid_at >= :active_cutoff
    GROUP BY currency
    ORDER BY currency
");
$subscriptionRevenueStmt->execute(['active_cutoff' => $activeCutoff]);
$subscriptionRevenue = $subscriptionRevenueStmt->fetchAll();

$activePremiumStmt = $db->prepare("
    SELECT p.monthly_price, p.yearly_price, p.monthly_price_xaf, p.yearly_price_xaf,
           (
               SELECT b.country
               FROM businesses b
               WHERE b.user_id = s.user_id
               ORDER BY b.id DESC
               LIMIT 1
           ) AS country,
           (
               SELECT t.billing_cycle
               FROM subscription_transactions t
               WHERE t.subscription_id = s.id
                 AND t.plan_id = s.plan_id
                 AND t.status = 'paid'
               ORDER BY COALESCE(t.paid_at, t.created_at) DESC, t.id DESC
               LIMIT 1
           ) AS billing_cycle,
           (
               SELECT t.amount
               FROM subscription_transactions t
               WHERE t.subscription_id = s.id
                 AND t.plan_id = s.plan_id
                 AND t.status = 'paid'
               ORDER BY COALESCE(t.paid_at, t.created_at) DESC, t.id DESC
               LIMIT 1
           ) AS last_paid_amount,
           (
               SELECT t.currency
               FROM subscription_transactions t
               WHERE t.subscription_id = s.id
                 AND t.plan_id = s.plan_id
                 AND t.status = 'paid'
               ORDER BY COALESCE(t.paid_at, t.created_at) DESC, t.id DESC
               LIMIT 1
           ) AS last_paid_currency
    FROM subscriptions s
    JOIN plans p ON p.id = s.plan_id
    WHERE p.code <> 'free'
      AND s.status = 'active'
      AND (s.current_period_end IS NULL OR s.current_period_end > :now)
");
$activePremiumStmt->execute(['now' => $now]);
$monthlyRecurringRevenue = [];
foreach ($activePremiumStmt->fetchAll() as $subscription) {
    $currency = $subscription['last_paid_currency'] ?: subscriptionCurrencyForCountry($subscription['country']);
    $billingCycle = $subscription['billing_cycle'];
    $lastPaidAmount = $subscription['last_paid_amount'];

    if ($lastPaidAmount !== null) {
        $monthlyAmount = (float) $lastPaidAmount / ($billingCycle === 'yearly' ? 12 : 1);
    } elseif ($currency === 'XAF') {
        $monthlyAmount = (float) ($subscription['monthly_price_xaf'] ?? 0);
        if ($monthlyAmount <= 0) {
            $monthlyAmount = (float) ($subscription['yearly_price_xaf'] ?? 0) / 12;
        }
    } else {
        $monthlyAmount = (float) ($subscription['monthly_price'] ?? 0);
        if ($monthlyAmount <= 0) {
            $monthlyAmount = (float) ($subscription['yearly_price'] ?? 0) / 12;
        }
    }

    if ($monthlyAmount > 0) {
        $monthlyRecurringRevenue[$currency] = ($monthlyRecurringRevenue[$currency] ?? 0) + $monthlyAmount;
    }
}
ksort($monthlyRecurringRevenue);

$recentTransactionsStmt = $db->query("
    SELECT t.amount, t.currency, t.status, t.billing_cycle, t.created_at, t.paid_at,
           u.name AS user_name, u.email, p.name AS plan_name
    FROM subscription_transactions t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN plans p ON p.id = t.plan_id
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 10
");
$recentTransactions = $recentTransactionsStmt->fetchAll();

$failedPaymentsStmt = $db->prepare("
    SELECT t.amount, t.currency, t.provider, t.created_at, t.description,
           u.name AS user_name, u.email, p.name AS plan_name
    FROM subscription_transactions t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN plans p ON p.id = t.plan_id
    WHERE t.status = 'failed' AND t.created_at >= :active_cutoff
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 10
");
$failedPaymentsStmt->execute(['active_cutoff' => $activeCutoff]);
$failedPayments = $failedPaymentsStmt->fetchAll();

$recentRegistrationsStmt = $db->query('SELECT name, email, created_at FROM users ORDER BY created_at DESC, id DESC LIMIT 10');
$recentRegistrations = $recentRegistrationsStmt->fetchAll();

$aiUsageStmt = $db->prepare('SELECT COALESCE(SUM(request_count), 0) FROM ai_usage WHERE usage_date >= :date_cutoff');
$aiUsageStmt->execute(['date_cutoff' => $dateCutoff]);
$aiRequests30d = (int) $aiUsageStmt->fetchColumn();
$auditStmt = $db->query('SELECT l.action, l.created_at, u.email FROM admin_audit_log l JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 20');
$auditRows = $auditStmt->fetchAll();
$plans = $db->query('SELECT id, name, code, monthly_price, yearly_price, monthly_price_xaf, yearly_price_xaf, is_available, admin_customized FROM plans ORDER BY id')->fetchAll();
$search = trim((string) ($_GET['q'] ?? ''));
$userQuery = "
    SELECT u.id, u.name, u.email, u.role, u.created_at, u.last_activity_at,
           (SELECT b.name FROM businesses b WHERE b.user_id = u.id ORDER BY b.id DESC LIMIT 1) AS business_name,
           p.name AS plan_name, p.code AS plan_code
    FROM users u
    LEFT JOIN subscriptions s ON s.user_id = u.id
    LEFT JOIN plans p ON p.id = s.plan_id
";
$userParams = [];
if ($search !== '') {
    $userQuery .= ' WHERE u.name LIKE :search_name OR u.email LIKE :search_email OR EXISTS (SELECT 1 FROM businesses sb WHERE sb.user_id = u.id AND sb.name LIKE :search_business)';
    $userParams = [
        'search_name' => '%' . $search . '%',
        'search_email' => '%' . $search . '%',
        'search_business' => '%' . $search . '%',
    ];
}
$userQuery .= ' ORDER BY u.created_at DESC, u.id DESC LIMIT 50';
$usersStmt = $db->prepare($userQuery);
$usersStmt->execute($userParams);
$users = $usersStmt->fetchAll();

$businesses = $db->query("
    SELECT b.name, b.email, b.city, b.country, b.created_at, u.name AS owner_name, u.email AS owner_email
    FROM businesses b
    JOIN users u ON u.id = b.user_id
    ORDER BY b.created_at DESC, b.id DESC
    LIMIT 50
")->fetchAll();
$subscriptions = $db->query("
    SELECT s.id, s.user_id, s.status, s.current_period_start, s.current_period_end, s.cancel_at_period_end,
           u.name AS user_name, u.email, p.name AS plan_name, p.code AS plan_code
    FROM subscriptions s
    JOIN users u ON u.id = s.user_id
    JOIN plans p ON p.id = s.plan_id
    ORDER BY s.updated_at DESC, s.id DESC
    LIMIT 50
")->fetchAll();
$transactionStatus = trim((string) ($_GET['transaction_status'] ?? ''));
$transactionQuery = "
    SELECT t.id, t.user_id, t.amount, t.currency, t.status, t.billing_cycle, t.provider, t.promo_code,
           t.discount_amount, t.created_at, t.paid_at, u.name AS user_name, u.email, p.name AS plan_name
    FROM subscription_transactions t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN plans p ON p.id = t.plan_id
";
$transactionParams = [];
if (in_array($transactionStatus, ['pending', 'paid', 'failed', 'cancelled', 'expired'], true)) {
    $transactionQuery .= ' WHERE t.status = :status';
    $transactionParams['status'] = $transactionStatus;
} else {
    $transactionStatus = '';
}
$transactionQuery .= ' ORDER BY t.created_at DESC, t.id DESC LIMIT 100';
$transactionsStmt = $db->prepare($transactionQuery);
$transactionsStmt->execute($transactionParams);
$transactions = $transactionsStmt->fetchAll();
$promos = $db->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM subscription_transactions t WHERE t.promo_code_id = p.id AND t.status = 'paid') AS redemption_count,
           (SELECT COUNT(*) FROM subscription_transactions t WHERE t.promo_code_id = p.id AND t.status = 'pending') AS pending_count
    FROM promo_codes p
    ORDER BY p.created_at DESC, p.id DESC
")->fetchAll();
$announcements = $db->query('SELECT id, title, message, starts_at, ends_at, is_active, created_at FROM announcements ORDER BY created_at DESC, id DESC')->fetchAll();

$monthStart = (new DateTimeImmutable('first day of this month', new DateTimeZone('UTC')))->modify('-5 months');
$analyticsStart = $monthStart->format('Y-m-01');
$monthLabels = [];
for ($offset = 0; $offset < 6; $offset++) {
    $month = $monthStart->modify('+' . $offset . ' months');
    $monthLabels[$month->format('Y-m')] = $month->format('M');
}
$analyticsUsers = array_fill_keys(array_keys($monthLabels), 0);
$analyticsInvoices = array_fill_keys(array_keys($monthLabels), 0);
$analyticsPaid = [];
$analyticsPaidCounts = [];
$monthlyUsersStmt = $db->prepare('SELECT SUBSTR(created_at, 1, 7) AS month_key, COUNT(*) AS total FROM users WHERE created_at >= :cutoff GROUP BY SUBSTR(created_at, 1, 7)');
$monthlyUsersStmt->execute(['cutoff' => $analyticsStart]);
foreach ($monthlyUsersStmt->fetchAll() as $row) {
    if (isset($analyticsUsers[$row['month_key']])) {
        $analyticsUsers[$row['month_key']] = (int) $row['total'];
    }
}
$monthlyInvoicesStmt = $db->prepare('SELECT SUBSTR(created_at, 1, 7) AS month_key, COUNT(*) AS total FROM invoices WHERE created_at >= :cutoff GROUP BY SUBSTR(created_at, 1, 7)');
$monthlyInvoicesStmt->execute(['cutoff' => $analyticsStart]);
foreach ($monthlyInvoicesStmt->fetchAll() as $row) {
    if (isset($analyticsInvoices[$row['month_key']])) {
        $analyticsInvoices[$row['month_key']] = (int) $row['total'];
    }
}
$monthlyRevenueStmt = $db->prepare("SELECT SUBSTR(paid_at, 1, 7) AS month_key, currency, COUNT(*) AS payment_count, SUM(amount) AS total FROM subscription_transactions WHERE status = 'paid' AND paid_at >= :cutoff GROUP BY SUBSTR(paid_at, 1, 7), currency ORDER BY month_key, currency");
$monthlyRevenueStmt->execute(['cutoff' => $analyticsStart]);
foreach ($monthlyRevenueStmt->fetchAll() as $row) {
    $analyticsPaid[$row['month_key']][$row['currency']] = (float) $row['total'];
    $analyticsPaidCounts[$row['month_key']][$row['currency']] = (int) $row['payment_count'];
}
$maxUsersByMonth = max(1, ...array_values($analyticsUsers));
$maxInvoicesByMonth = max(1, ...array_values($analyticsInvoices));
$invoiceStatusRows = $db->query('SELECT status, COUNT(*) AS total FROM invoices GROUP BY status ORDER BY status')->fetchAll();
$roleCounts = $db->query("SELECT role, COUNT(*) AS total FROM users GROUP BY role ORDER BY role")->fetchAll();
$userGrowthStmt = $db->prepare('SELECT COUNT(*) FROM users WHERE created_at >= :week_cutoff');
$userGrowthStmt->execute(['week_cutoff' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
$newUsers7d = (int) $userGrowthStmt->fetchColumn();
$invoiceGrowthStmt = $db->prepare('SELECT COUNT(*) FROM invoices WHERE created_at >= :week_cutoff');
$invoiceGrowthStmt->execute(['week_cutoff' => gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
$newInvoices7d = (int) $invoiceGrowthStmt->fetchColumn();

$pageTitle = 'Administration';
$siteTitle = 'Administration | Invoices Generators';
$activeNav = 'admin.php';
$adminSidebarMode = true;
include __DIR__ . '/includes/header.php';
?>
<div class="admin-dashboard-layout app-page">
    <main class="admin-dashboard-content" id="dashboard">
        <header class="admin-dashboard-heading">
            <div><span class="eyebrow">Platform overview</span><h1>Admin Dashboard</h1><p>Key account, subscription and billing activity from the last 30 days where noted.</p></div>
            <span class="admin-period-badge">Updated <?= e(gmdate('M j, Y')); ?> UTC</span>
        </header>
        <?php if ($errorMessage): ?><div class="notice notice-error" role="alert"><?= e($errorMessage); ?></div><?php endif; ?>
        <?php if ($successMessage): ?><div class="notice notice-success" role="status"><?= e($successMessage); ?></div><?php endif; ?>

        <section class="admin-overview-grid" aria-label="Admin overview metrics">
            <article class="admin-overview-card"><span>Total registered users</span><strong><?= $userCount; ?></strong><small>All accounts</small></article>
            <article class="admin-overview-card"><span>Active users</span><strong><?= $activeUserCount; ?></strong><small>Used the app in the last 30 days</small></article>
            <article class="admin-overview-card"><span>New users · 7 days</span><strong><?= $newUsers7d; ?></strong><small>Recent registrations</small></article>
            <article class="admin-overview-card" id="premium-subscribers"><span>Premium subscribers</span><strong><?= $paidSubscriptionCount; ?></strong><small>Current, unexpired subscriptions</small></article>
            <article class="admin-overview-card"><span>Total invoices generated</span><strong><?= $invoiceCount; ?></strong><small>Across all accounts</small></article>
            <article class="admin-overview-card"><span>New invoices · 7 days</span><strong><?= $newInvoices7d; ?></strong><small>Invoice creation activity</small></article>
            <article class="admin-overview-card admin-overview-card-wide" id="revenue-reports">
                <span>Subscription revenue · last 30 days</span>
                <?php if ($subscriptionRevenue): ?>
                    <div class="admin-revenue-values">
                        <?php foreach ($subscriptionRevenue as $revenue): ?>
                            <strong><?= e($revenue['currency']); ?> <?= e(number_format((float) $revenue['total_amount'], 2)); ?><small><?= (int) $revenue['payment_count']; ?> paid</small></strong>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <strong>No confirmed revenue</strong>
                <?php endif; ?>
                <small>Confirmed subscription payments only; currencies are not combined.</small>
            </article>
            <article class="admin-overview-card admin-overview-card-wide">
                <span>Estimated monthly recurring revenue</span>
                <?php if ($monthlyRecurringRevenue): ?>
                    <div class="admin-revenue-values">
                        <?php foreach ($monthlyRecurringRevenue as $currency => $amount): ?>
                            <strong><?= e($currency); ?> <?= e(number_format($amount, 2)); ?></strong>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <strong>No active recurring revenue</strong>
                <?php endif; ?>
                <small>Estimate from active premium subscriptions and their latest paid billing cycle or listed plan price.</small>
            </article>
        </section>

        <section class="card admin-data-card" id="users">
            <div class="card-head"><div><h2>User accounts</h2><p>Search users and manage administrator access. The current administrator cannot remove their own access.</p></div></div>
            <form method="get" class="admin-search-form">
                <label for="admin-user-search">Search name, email or business</label>
                <div><input id="admin-user-search" name="q" value="<?= e($search); ?>" type="search" maxlength="160" placeholder="Search accounts"><button class="btn btn-secondary" type="submit">Search</button><?php if ($search !== ''): ?><a class="workflow-link" href="admin.php#users">Clear</a><?php endif; ?></div>
            </form>
            <details class="admin-collapsible">
                <summary>
                    <span class="admin-collapsible-copy"><strong>Account list</strong><small>Showing up to 50 matching accounts. Role changes are recorded in the admin audit log.</small></span>
                    <span class="admin-collapsible-toggle" aria-hidden="true"></span>
                </summary>
                <div class="admin-collapsible-content">
                    <div class="table-wrap"><table class="data-table"><thead><tr><th>User</th><th>Business</th><th>Plan</th><th>Activity</th><th>Role</th></tr></thead><tbody>
                        <?php if (!$users): ?><tr><td colspan="5">No matching accounts.</td></tr><?php endif; ?>
                        <?php foreach ($users as $account): ?>
                            <tr>
                                <td><?= e($account['name']); ?><small class="admin-cell-detail"><?= e($account['email']); ?> · joined <?= e(substr((string) $account['created_at'], 0, 10)); ?></small></td>
                                <td><?= e($account['business_name'] ?: '—'); ?></td>
                                <td><?= e($account['plan_name'] ?: 'Not assigned'); ?></td>
                                <td><?= e($account['last_activity_at'] ?: 'No activity recorded'); ?></td>
                                <td>
                                    <form method="post" class="admin-row-form">
                                        <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                                        <input type="hidden" name="action" value="update_user_role">
                                        <input type="hidden" name="user_id" value="<?= (int) $account['id']; ?>">
                                        <select name="role" aria-label="Role for <?= e($account['email']); ?>">
                                            <option value="user"<?= $account['role'] !== 'admin' ? ' selected' : ''; ?>>User</option>
                                            <option value="admin"<?= $account['role'] === 'admin' ? ' selected' : ''; ?>>Admin</option>
                                        </select>
                                        <button class="btn btn-secondary" type="submit">Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                </div>
            </details>
        </section>

        <section class="card admin-data-card" id="business-profiles">
            <div class="card-head"><div><h2>Business profiles</h2><p>Registered business contact and location details.</p></div></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Business</th><th>Owner</th><th>Location</th><th>Created</th></tr></thead><tbody>
                <?php if (!$businesses): ?><tr><td colspan="4">No business profiles yet.</td></tr><?php endif; ?>
                <?php foreach ($businesses as $businessProfile): ?>
                    <tr><td><?= e($businessProfile['name']); ?><small class="admin-cell-detail"><?= e($businessProfile['email'] ?: 'No business email'); ?></small></td><td><?= e($businessProfile['owner_name']); ?><small class="admin-cell-detail"><?= e($businessProfile['owner_email']); ?></small></td><td><?= e(implode(', ', array_filter([$businessProfile['city'], $businessProfile['country']])) ?: '—'); ?></td><td><?= e($businessProfile['created_at']); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card admin-data-card" id="subscriptions">
            <div class="card-head"><div><h2>Subscriptions</h2><p>Review current access and safely reset a paid plan to Free when needed.</p></div></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Account</th><th>Plan</th><th>Status</th><th>Period end</th><th>Action</th></tr></thead><tbody>
                <?php if (!$subscriptions): ?><tr><td colspan="5">No subscriptions yet.</td></tr><?php endif; ?>
                <?php foreach ($subscriptions as $subscription): ?>
                    <tr>
                        <td><?= e($subscription['user_name']); ?><small class="admin-cell-detail"><?= e($subscription['email']); ?></small></td>
                        <td><?= e($subscription['plan_name']); ?></td>
                        <td><?= e(ucfirst(str_replace('_', ' ', $subscription['status']))); ?><?= (int) $subscription['cancel_at_period_end'] === 1 ? ' · ends at period' : ''; ?></td>
                        <td><?= e($subscription['current_period_end'] ?: 'No expiry'); ?></td>
                        <td><?php if ($subscription['plan_code'] !== 'free'): ?><form method="post" class="admin-row-form" onsubmit="return confirm('Reset this account to the Free plan? The action will be audited.');"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="action" value="reset_subscription"><input type="hidden" name="user_id" value="<?= (int) $subscription['user_id']; ?>"><button class="btn btn-secondary" type="submit">Reset to Free</button></form><?php else: ?>—<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="admin-analytics-grid" id="analytics">
            <article class="card admin-data-card">
                <div class="card-head"><div><h2>Account growth</h2><p>New registrations by month · last 6 months.</p></div></div>
                <div class="admin-bar-chart" role="img" aria-label="Monthly user registrations for the last six months">
                    <?php foreach ($monthLabels as $monthKey => $monthLabel): ?>
                        <?php $barHeight = max(4, (int) round(($analyticsUsers[$monthKey] / $maxUsersByMonth) * 100)); ?>
                        <div class="admin-chart-column"><strong><?= $analyticsUsers[$monthKey]; ?></strong><span class="admin-chart-bar admin-chart-bar-users" style="--bar-height: <?= $barHeight; ?>%"></span><small><?= e($monthLabel); ?></small></div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="card admin-data-card">
                <div class="card-head"><div><h2>Invoice activity</h2><p>Created invoices by month · last 6 months.</p></div></div>
                <div class="admin-bar-chart" role="img" aria-label="Monthly invoice creation for the last six months">
                    <?php foreach ($monthLabels as $monthKey => $monthLabel): ?>
                        <?php $barHeight = max(4, (int) round(($analyticsInvoices[$monthKey] / $maxInvoicesByMonth) * 100)); ?>
                        <div class="admin-chart-column"><strong><?= $analyticsInvoices[$monthKey]; ?></strong><span class="admin-chart-bar admin-chart-bar-invoices" style="--bar-height: <?= $barHeight; ?>%"></span><small><?= e($monthLabel); ?></small></div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="card admin-data-card admin-analytics-wide">
                <div class="card-head"><div><h2>Confirmed revenue trend</h2><p>Paid subscription transactions by month and currency; currencies stay separate.</p></div></div>
                <div class="table-wrap"><table class="data-table"><thead><tr><th>Month</th><th>Paid subscriptions</th><th>Revenue</th></tr></thead><tbody>
                    <?php foreach ($monthLabels as $monthKey => $monthLabel): ?>
                        <?php $monthlyTotals = $analyticsPaid[$monthKey] ?? []; ?>
                        <tr>
                            <td><?= e($monthLabel . ' ' . substr($monthKey, 0, 4)); ?></td>
                            <td><?= array_sum($analyticsPaidCounts[$monthKey] ?? []); ?></td>
                            <td><?php if (!$monthlyTotals): ?>—<?php else: ?><?php $currencyIndex = 0; foreach ($monthlyTotals as $currency => $amount): ?><?= e($currency); ?> <?= e(number_format($amount, 2)); ?><?= ++$currencyIndex < count($monthlyTotals) ? ' · ' : ''; ?><?php endforeach; ?><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <div class="admin-analytics-breakdowns">
                    <div><h3>Invoice statuses</h3><?php foreach ($invoiceStatusRows as $statusRow): ?><span><?= e(ucfirst($statusRow['status'])); ?><strong><?= (int) $statusRow['total']; ?></strong></span><?php endforeach; ?></div>
                    <div><h3>Account roles</h3><?php foreach ($roleCounts as $roleCount): ?><span><?= e(ucfirst($roleCount['role'])); ?><strong><?= (int) $roleCount['total']; ?></strong></span><?php endforeach; ?></div>
                </div>
            </article>
        </section>

        <section class="card admin-data-card" id="latest-transactions">
            <div class="card-head"><div><h2>Recent transactions</h2><p>Latest subscription payment attempts.</p></div><a class="workflow-link" href="#failed-payments">View failed payments ↓</a></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Customer</th><th>Plan</th><th>Amount</th><th>Status</th><th>Created</th></tr></thead><tbody>
            <?php if (!$recentTransactions): ?><tr><td colspan="5">No transactions recorded yet.</td></tr><?php endif; ?>
            <?php foreach ($recentTransactions as $transaction): ?>
                <tr>
                    <td><?= e($transaction['user_name']); ?><small class="admin-cell-detail"><?= e($transaction['email']); ?></small></td>
                    <td><?= e($transaction['plan_name'] ?: 'Plan unavailable'); ?></td>
                    <td><?= e($transaction['currency']); ?> <?= e(number_format((float) $transaction['amount'], 2)); ?></td>
                    <td><span class="admin-status admin-status-<?= e(preg_replace('/[^a-z0-9-]/', '', strtolower($transaction['status']))); ?>"><?= e(ucfirst($transaction['status'])); ?></span></td>
                    <td><?= e($transaction['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card admin-data-card" id="transactions">
            <div class="card-head"><div><h2>Transaction history</h2><p>Searchable by status; up to 100 newest payment records. Payment records are read-only to preserve financial history.</p></div></div>
            <form method="get" class="admin-filter-form">
                <label for="transaction-status-filter">Status</label>
                <select id="transaction-status-filter" name="transaction_status">
                    <option value="">All statuses</option>
                    <?php foreach (['pending', 'paid', 'failed', 'cancelled', 'expired'] as $status): ?>
                        <option value="<?= e($status); ?>"<?= $transactionStatus === $status ? ' selected' : ''; ?>><?= e(ucfirst($status)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-secondary" type="submit">Filter</button>
            </form>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Transaction</th><th>Account</th><th>Plan</th><th>Amount paid</th><th>Status</th><th>Provider / date</th></tr></thead><tbody>
                <?php if (!$transactions): ?><tr><td colspan="6">No transactions match this filter.</td></tr><?php endif; ?>
                <?php foreach ($transactions as $transaction): ?>
                    <tr>
                        <td>#<?= (int) $transaction['id']; ?><?php if ($transaction['promo_code']): ?><small class="admin-cell-detail">Promo <?= e($transaction['promo_code']); ?> · saved <?= e($transaction['currency']); ?> <?= e(number_format((float) $transaction['discount_amount'], 2)); ?></small><?php endif; ?></td>
                        <td><?= e($transaction['user_name']); ?><small class="admin-cell-detail"><?= e($transaction['email']); ?></small></td>
                        <td><?= e($transaction['plan_name'] ?: 'Plan unavailable'); ?><small class="admin-cell-detail"><?= e(ucfirst($transaction['billing_cycle'])); ?></small></td>
                        <td><?= e($transaction['currency']); ?> <?= e(number_format((float) $transaction['amount'], 2)); ?></td>
                        <td><span class="admin-status admin-status-<?= e(preg_replace('/[^a-z0-9-]/', '', strtolower($transaction['status']))); ?>"><?= e(ucfirst($transaction['status'])); ?></span></td>
                        <td><?= e($transaction['provider'] ?: '—'); ?><small class="admin-cell-detail"><?= e($transaction['paid_at'] ?: $transaction['created_at']); ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card admin-data-card" id="failed-payments">
            <div class="card-head"><div><h2>Failed payments</h2><p>Failed subscription payment attempts in the last 30 days.</p></div></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Customer</th><th>Plan</th><th>Amount</th><th>Provider</th><th>Attempted</th></tr></thead><tbody>
            <?php if (!$failedPayments): ?><tr><td colspan="5">No failed payments in the last 30 days.</td></tr><?php endif; ?>
            <?php foreach ($failedPayments as $payment): ?>
                <tr><td><?= e($payment['user_name']); ?><small class="admin-cell-detail"><?= e($payment['email']); ?></small></td><td><?= e($payment['plan_name'] ?: 'Plan unavailable'); ?></td><td><?= e($payment['currency']); ?> <?= e(number_format((float) $payment['amount'], 2)); ?></td><td><?= e($payment['provider'] ?: '—'); ?></td><td><?= e($payment['created_at']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card admin-data-card" id="recent-registrations">
            <div class="card-head"><div><h2>Recent registrations</h2><p>Newest accounts on the platform.</p></div></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Email</th><th>Registered (UTC)</th></tr></thead><tbody>
            <?php if (!$recentRegistrations): ?><tr><td colspan="3">No registrations yet.</td></tr><?php endif; ?>
            <?php foreach ($recentRegistrations as $registration): ?>
                <tr><td><?= e($registration['name']); ?></td><td><?= e($registration['email']); ?></td><td><?= e($registration['created_at']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card admin-data-card" id="pricing-plans">
            <div class="card-head"><div><h2>Pricing plans</h2><p>Edit new-checkout pricing and availability. Existing subscription periods and paid transactions are not changed.</p></div></div>
            <div class="admin-plan-grid">
            <?php foreach ($plans as $plan): ?>
                <form method="post" class="admin-plan-form">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                    <input type="hidden" name="action" value="update_plan">
                    <input type="hidden" name="plan_id" value="<?= (int) $plan['id']; ?>">
                    <header><span><?= e($plan['name']); ?></span><small><?= e(strtoupper($plan['code'])); ?></small></header>
                    <div class="admin-price-fields">
                        <?php foreach ([
                            'monthly_price' => 'Monthly USD',
                            'yearly_price' => 'Yearly USD',
                            'monthly_price_xaf' => 'Monthly XAF',
                            'yearly_price_xaf' => 'Yearly XAF',
                        ] as $field => $label): ?>
                            <label><?= e($label); ?><input name="<?= e($field); ?>" type="number" min="0" max="1000000000" step="0.01" value="<?= $plan[$field] === null ? '' : e((string) $plan[$field]); ?>" placeholder="Not offered"></label>
                        <?php endforeach; ?>
                    </div>
                    <label class="admin-checkbox-label"><input type="checkbox" name="is_available" value="1"<?= (int) $plan['is_available'] === 1 ? ' checked' : ''; ?><?= $plan['code'] === 'free' ? ' disabled checked' : ''; ?>> Available for new checkouts</label>
                    <?php if ($plan['code'] === 'free'): ?><input type="hidden" name="is_available" value="1"><?php endif; ?>
                    <button class="btn btn-secondary" type="submit">Save plan</button>
                </form>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="card admin-data-card" id="promo-codes">
            <div class="card-head"><div><h2>Promo codes</h2><p>Manage discounts available in subscription checkout. Pending checkouts reserve capped uses until they resolve; confirmed redemptions count after provider payment.</p></div></div>
            <form method="post" class="admin-create-form">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                <input type="hidden" name="action" value="create_promo">
                <label>Code<input name="code" type="text" maxlength="40" pattern="[A-Za-z0-9_-]{3,40}" required placeholder="WELCOME10"></label>
                <label>Discount type<select name="discount_type"><option value="percent">Percent</option><option value="fixed">Fixed amount</option></select></label>
                <label>Value<input name="discount_value" type="number" min="0.01" max="1000000000" step="0.01" required></label>
                <label>Currency<select name="currency"><option value="BOTH">Both · USD &amp; XAF (percent only)</option><option value="USD">USD only</option><option value="XAF">XAF only</option></select></label>
                <label>Max uses<input name="max_redemptions" type="number" min="1" step="1" placeholder="Unlimited"></label>
                <label>Expires on<input name="expires_at" type="date"></label>
                <button class="btn btn-primary" type="submit">Create promo code</button>
            </form>
            <p class="admin-table-note">A percent promo can cover both USD and XAF. Fixed-amount promos apply to one currency; create separate codes when you need different fixed discounts.</p>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Code</th><th>Discount</th><th>Usage · paid / pending / cap</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>
                <?php if (!$promos): ?><tr><td colspan="6">No promo codes created yet.</td></tr><?php endif; ?>
                <?php foreach ($promos as $promo): ?>
                    <tr><td><strong><?= e($promo['code']); ?></strong></td><td><?= $promo['discount_type'] === 'percent' ? e(number_format((float) $promo['discount_value'], 2)) . '%' : e($promo['currency']) . ' ' . e(number_format((float) $promo['discount_value'], 2)); ?><?php if ($promo['currency'] === 'BOTH'): ?><small class="admin-cell-detail">USD &amp; XAF</small><?php endif; ?></td><td><?= (int) $promo['redemption_count']; ?> / <?= (int) $promo['pending_count']; ?> / <?= $promo['max_redemptions'] === null ? 'unlimited' : (int) $promo['max_redemptions']; ?></td><td><?= e($promo['expires_at'] ?: 'Never'); ?></td><td><?= (int) $promo['is_active'] === 1 ? 'Active' : 'Paused'; ?></td><td><form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="action" value="toggle_promo"><input type="hidden" name="promo_id" value="<?= (int) $promo['id']; ?>"><button class="btn btn-secondary" type="submit"><?= (int) $promo['is_active'] === 1 ? 'Pause' : 'Activate'; ?></button></form></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card admin-data-card" id="announcements">
            <div class="card-head"><div><h2>Announcements</h2><p>Active announcements are shown to signed-in non-admin users during their scheduled dates.</p></div></div>
            <form method="post" class="admin-announcement-form">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                <input type="hidden" name="action" value="create_announcement">
                <label>Title<input name="title" type="text" maxlength="160" required placeholder="Planned maintenance"></label>
                <label>Message<textarea name="message" rows="3" maxlength="2000" required placeholder="Write an update for customers"></textarea></label>
                <div class="admin-date-fields"><label>Starts on<input name="starts_at" type="date"></label><label>Ends on<input name="ends_at" type="date"></label></div>
                <button class="btn btn-primary" type="submit">Publish announcement</button>
            </form>
            <div class="admin-announcement-list">
                <?php if (!$announcements): ?><p class="admin-table-note">No announcements published.</p><?php endif; ?>
                <?php foreach ($announcements as $announcement): ?>
                    <article><div><strong><?= e($announcement['title']); ?></strong><p><?= e($announcement['message']); ?></p><small><?= e($announcement['starts_at'] ?: 'Starts now'); ?><?= $announcement['ends_at'] ? ' · through ' . e($announcement['ends_at']) : ''; ?></small></div><form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="action" value="toggle_announcement"><input type="hidden" name="announcement_id" value="<?= (int) $announcement['id']; ?>"><button class="btn btn-secondary" type="submit"><?= (int) $announcement['is_active'] === 1 ? 'Unpublish' : 'Publish'; ?></button></form></article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card admin-data-card" id="ai-management">
            <div class="card-head"><div><h2>AI management</h2><p>Tracked AI request volume for the last 30 days.</p></div><strong><?= $aiRequests30d; ?> requests</strong></div>
        </section>

        <section class="card admin-data-card" id="security-logs">
            <details class="admin-collapsible">
                <summary>
                    <span class="admin-collapsible-copy"><strong>Security and admin audit log</strong><small>Recent recorded administrator actions.</small></span>
                    <span class="admin-collapsible-toggle" aria-hidden="true"></span>
                </summary>
                <div class="admin-collapsible-content">
                    <div class="table-wrap"><table class="data-table"><thead><tr><th>Administrator</th><th>Action</th><th>Date</th></tr></thead><tbody>
                    <?php if (!$auditRows): ?><tr><td colspan="3">No admin activity yet.</td></tr><?php endif; ?>
                    <?php foreach ($auditRows as $audit): ?><tr><td><?= e($audit['email']); ?></td><td><?= e($audit['action']); ?></td><td><?= e($audit['created_at']); ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                    <div class="notice">Access is currently limited to accounts explicitly assigned the admin role. Admin access to customer financial documents is not provided on this page.</div>
                </div>
            </details>
        </section>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
