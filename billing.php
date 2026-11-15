<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/subscription_payments.php';
requireAuth($db);

$userId = (int) $_SESSION['user_id'];
$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}
provisionFreeSubscription($db, $userId);
$subscription = getUserSubscription($db, $userId);
if (!$subscription) {
    throw new RuntimeException('The subscription record could not be loaded.');
}
$currency = subscriptionCurrencyForCountry((string) ($business['country'] ?? ''));
$planCatalog = subscriptionPlanCatalog($db);
$planEntitlements = getPlanEntitlements($db, $userId);
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'checkout') {
            $checkoutUrl = createSubscriptionCheckout(
                $db,
                $userId,
                (string) ($_POST['plan'] ?? ''),
                (string) ($_POST['cycle'] ?? ''),
                (string) ($_POST['payment_method'] ?? ''),
                getCurrentUser($db) ?? [],
                $business
            );
            header('Location: ' . $checkoutUrl, true, 303);
            exit;
        }
        if ($action === 'cancel_pending_checkout') {
            $transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT);
            if ($transactionId === false || $transactionId === null || $transactionId < 1
                || !cancelPendingSubscriptionCheckout($db, $userId, $transactionId)) {
                throw new InvalidArgumentException('That payment is no longer pending. Refresh Billing history to see its current status.');
            }
            setFlash('billing_success', 'Pending payment cancelled in your billing history. You can now start a new checkout. If you already paid the provider, contact support.');
            redirect('billing.php');
        }
        if ($action === 'cancel') {
            if (!cancelSubscriptionAtPeriodEnd($db, $userId)) {
                throw new InvalidArgumentException('There is no active paid plan to cancel.');
            }
            setFlash('billing_success', 'Your paid access will remain active until the current period ends. It will not renew automatically.');
            redirect('billing.php');
        }
        if ($action === 'check_fapshi') {
            $transactionStatus = checkFapshiSubscriptionTransaction($db, $userId, (int) ($_POST['transaction_id'] ?? 0));
            setFlash('billing_success', match ($transactionStatus) {
                'paid' => 'Your payment is confirmed. Your plan is active.',
                'failed' => 'Your payment failed. You can start a new checkout.',
                'expired' => 'That payment link has expired. You can start a new checkout.',
                default => 'Your payment is not confirmed yet. You can check again shortly.',
            });
            redirect('billing.php');
        }
        throw new InvalidArgumentException('Choose an available billing action.');
    } catch (Throwable $exception) {
        if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
            $errorMessage = $exception->getMessage();
        } else {
            error_log('Subscription action failed for user ' . $userId . ': ' . $exception->getMessage());
            $errorMessage = 'The billing action could not be completed. No paid access was granted.';
        }
    }
}

$usage = [
    'invoices' => getPlanFeatureUsage($db, $userId, (int) $business['id'], 'invoices'),
    'customers' => getPlanFeatureUsage($db, $userId, (int) $business['id'], 'customers'),
    'products' => getPlanFeatureUsage($db, $userId, (int) $business['id'], 'products'),
    'ai_requests' => getPlanFeatureUsage($db, $userId, (int) $business['id'], 'ai_requests'),
];
$transactions = $db->prepare('SELECT id, amount, currency, status, description, billing_cycle, checkout_url, provider, provider_transaction_id, paid_at, created_at FROM subscription_transactions WHERE user_id = :user_id ORDER BY created_at DESC, id DESC');
$transactions->execute(['user_id' => $userId]);
$billingHistory = $transactions->fetchAll();
$billingSuccess = getFlash('billing_success');
$paymentMessage = match ((string) ($_GET['payment'] ?? '')) {
    'pending' => 'The provider is confirming your payment. Paid access starts only after confirmation; use “Check payment” in your billing history if needed.',
    'cancelled' => 'Checkout was cancelled. No plan change was made.',
    default => null,
};
$countryPaymentMethods = subscriptionPaymentMethodsForCountry((string) ($business['country'] ?? ''));
$momoConfigured = in_array('momo', $countryPaymentMethods, true) && subscriptionPaymentProviderConfigured('XAF');
$cryptoConfigured = in_array('crypto', $countryPaymentMethods, true) && subscriptionPaymentProviderConfigured('USD');
$providerConfigured = $momoConfigured || $cryptoConfigured;
$configuredMethods = array_filter([
    $momoConfigured ? 'Mobile Money' : null,
    $cryptoConfigured ? 'Cryptocurrency' : null,
]);
$paymentMethodsSummary = $configuredMethods ? implode(' and ', $configuredMethods) : 'No payment method';

$pageTitle = 'Plans & billing';
$siteTitle = 'Plans & billing | Invoices Generators';
$activeNav = 'billing.php';
include __DIR__ . '/includes/header.php';
?>

<div class="app-page pricing-section billing-page">
    <div class="section-header billing-page-heading">
        <span class="eyebrow">Plans & billing</span>
        <h1>Plans that grow with your business</h1>
        <p>Choose the right level of access. Your limits and usage are always visible here.</p>
    </div>

    <?php if ($errorMessage): ?><div class="notice notice-error"><?= e($errorMessage); ?></div><?php endif; ?>
    <?php if ($billingSuccess): ?><div class="notice notice-success"><?= e($billingSuccess); ?></div><?php endif; ?>
    <?php if ($paymentMessage): ?><div class="notice"><?= e($paymentMessage); ?></div><?php endif; ?>
    <?php if (!$providerConfigured): ?>
        <div class="notice">Checkout is not enabled yet. Add the provider credentials and app URL on the server to accept payments.</div>
    <?php endif; ?>

    <section class="billing-current-plan" aria-labelledby="current-plan-heading">
        <div class="billing-current-copy">
            <span class="billing-kicker" id="current-plan-heading">YOUR CURRENT PLAN</span>
            <div class="billing-current-title">
                <strong><?= e($subscription['plan_name']); ?></strong>
                <span class="billing-status"><?= e(ucfirst(str_replace('_', ' ', $subscription['status']))); ?></span>
            </div>
            <p>
                <?= $currency === 'XAF' ? 'Cameroon billing · FCFA (XAF)' : 'International billing · USD'; ?>
                <?php if ($subscription['current_period_end']): ?>
                    <span class="billing-detail-separator">·</span> Access through <?= e((string) $subscription['current_period_end']); ?>
                <?php else: ?>
                    <span class="billing-detail-separator">·</span> No paid period is active
                <?php endif; ?>
            </p>
        </div>
        <?php if ($subscription['plan_code'] !== 'free' && empty($subscription['cancel_at_period_end'])): ?>
            <form method="post" class="inline-actions">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-secondary" type="submit">Cancel at period end</button>
            </form>
        <?php elseif (!empty($subscription['cancel_at_period_end'])): ?>
            <span class="billing-cancel-note">Cancellation scheduled</span>
        <?php endif; ?>
    </section>

    <section aria-labelledby="usage-heading">
        <div class="section-header">
            <span class="eyebrow">YOUR USAGE</span>
            <h2 id="usage-heading">Your plan usage</h2>
            <p>Monthly allowances reset at the start of each month. Customer and product limits apply to your workspace.</p>
        </div>
        <div class="stats-grid">
            <?php foreach ([
                ['Invoices', 'invoices', 'invoice_limit'],
                ['Customers', 'customers', 'customer_limit'],
                ['Products', 'products', 'product_limit'],
                ['AI requests', 'ai_requests', 'ai_limit'],
            ] as [$label, $key, $limitKey]): ?>
                <?php
                $limit = (int) $planEntitlements['plan'][$limitKey];
                $used = (int) $usage[$key];
                $usagePercent = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0;
                ?>
                <article class="stat-card">
                    <div class="billing-usage-heading">
                        <span class="metric-title"><?= e($label); ?></span>
                        <strong><?= number_format($used); ?><span> / <?= number_format($limit); ?></span></strong>
                    </div>
                    <div class="billing-usage-track" role="progressbar" aria-label="<?= e($label); ?> usage" aria-valuemin="0" aria-valuemax="<?= $limit; ?>" aria-valuenow="<?= min($used, $limit); ?>">
                        <span style="width: <?= $usagePercent; ?>%"></span>
                    </div>
                    <span class="billing-usage-caption"><?= $key === 'invoices' || $key === 'ai_requests' ? 'Resets monthly' : 'Across your workspace'; ?></span>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section aria-labelledby="available-plans-heading">
        <div class="section-header">
            <span class="eyebrow">SIMPLE, TRANSPARENT PRICING</span>
            <h2 id="available-plans-heading">Choose your plan</h2>
            <p><?= isCameroonCountry((string) ($business['country'] ?? '')) ? 'Cameroon billing: choose Mobile Money or cryptocurrency at checkout.' : 'International billing: pay in USD with cryptocurrency at checkout.'; ?></p>
        </div>
        <div class="plan-grid">
            <?php foreach ($planCatalog as $planCode => $plan): ?>
                <?php
                $cycle = $planCode === 'business' ? 'yearly' : 'monthly';
                $planPrice = subscriptionPrice($plan, $cycle, $currency);
                $isCurrentPlan = $planCode === $subscription['plan_code'];
                $isAvailable = !empty($plan['is_available']);
                ?>
                <article class="plan-card billing-plan-card billing-plan-<?= e($planCode); ?> <?= $isCurrentPlan ? 'is-current' : ''; ?> <?= $planCode === 'pro' ? 'is-featured' : ''; ?> <?= !$isAvailable && $planCode !== 'free' ? 'is-unavailable' : ''; ?>">
                    <div class="billing-plan-heading">
                        <div>
                            <h3><?= e($plan['name']); ?></h3>
                            <span class="billing-plan-audience"><?= $planCode === 'free' ? 'A GOOD PLACE TO BEGIN' : ($planCode === 'pro' ? 'FOR YOUR NEXT STAGE' : 'FOR ESTABLISHED WORKFLOWS'); ?></span>
                        </div>
                        <?php if (!$isAvailable && $planCode !== 'free'): ?><span class="billing-plan-tag">NOT AVAILABLE FOR NEW SALES</span><?php elseif ($isCurrentPlan): ?><span class="billing-plan-tag">CURRENT PLAN</span><?php elseif ($planCode === 'pro'): ?><span class="billing-plan-tag">POPULAR</span><?php endif; ?>
                    </div>
                    <p class="billing-plan-promise"><?= $planCode === 'free' ? 'Send a professional first invoice without paying a thing.' : ($planCode === 'pro' ? 'Look your best, serve more clients, and stay on top of the details.' : 'More room for a busy client list and a fuller month of invoicing.'); ?></p>
                    <?php if ($planCode === 'free'): ?>
                        <div class="plan-preview plan-preview-free" aria-hidden="true">
                            <div class="preview-paper">
                                <span class="preview-paper-brand"></span><span class="preview-paper-label">INVOICE</span>
                                <span class="preview-paper-line preview-paper-line-long"></span><span class="preview-paper-line"></span>
                                <span class="preview-paper-total"><i></i><b></b></span>
                            </div>
                            <span class="preview-caption">A polished invoice, ready to share</span>
                        </div>
                    <?php elseif ($planCode === 'pro'): ?>
                        <div class="plan-preview plan-preview-pro" aria-hidden="true">
                            <div class="preview-template preview-template-one"><i></i><b></b><span></span><span></span><em></em></div>
                            <div class="preview-template preview-template-two"><i></i><b></b><span></span><span></span><em></em></div>
                            <div class="preview-template preview-template-three"><i></i><b></b><span></span><span></span><em></em></div>
                            <span class="preview-count">20 designs</span>
                        </div>
                    <?php else: ?>
                        <div class="plan-preview plan-preview-business" aria-hidden="true">
                            <div class="preview-dashboard">
                                <span class="preview-dashboard-label">INVOICES THIS MONTH</span>
                                <div class="preview-dashboard-number"><b>More room to grow</b><i>↑</i></div>
                                <div class="preview-chart"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                                <div class="preview-dashboard-foot"><span>Clients</span><b>Organized</b></div>
                            </div>
                            <span class="preview-capacity">Up to <?= number_format($plan['invoice_limit']); ?> invoices / month</span>
                        </div>
                    <?php endif; ?>
                    <div class="plan-price">
                        <?= $planCode === 'free' ? 'Free' : ($planPrice === null ? '—' : e($currency === 'XAF' ? number_format($planPrice, 0) . ' FCFA' : '$' . number_format($planPrice, 0))); ?>
                        <?php if ($planCode !== 'free'): ?><small>/ <?= $cycle === 'yearly' ? 'year' : 'month'; ?></small><?php endif; ?>
                    </div>
                    <span class="billing-plan-cycle"><?= $planCode === 'free' ? 'Free forever' : 'Prepaid · no automatic renewal'; ?></span>
                    <div class="plan-highlights" aria-label="<?= e($plan['name']); ?> plan highlights">
                        <div><span>Invoices</span><strong><?= number_format($plan['invoice_limit']); ?> <small>/ month</small></strong></div>
                        <div><span>Clients & products</span><strong><?= number_format($plan['customer_limit']); ?> each</strong></div>
                        <div><span>Invoice styles</span><strong><?= $planCode === 'free' ? '2 designs' : 'All 20'; ?></strong></div>
                        <div><span>AI writing help</span><strong><?= number_format($plan['ai_limit']); ?> <small>/ month</small></strong></div>
                    </div>
                    <div class="plan-includes">
                        <span class="plan-include-icon">✓</span>
                        <span><?= $plan['quote_access'] ? 'Quotations & advanced reports' : 'Essential dashboard insights'; ?></span>
                        <?php if ($plan['quote_access']): ?>
                            <span class="plan-included-tag">Included</span>
                        <?php else: ?>
                            <span class="plan-not-included">No quotations</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($planCode === 'free'): ?>
                        <span class="btn btn-secondary billing-current-button"><?= $isCurrentPlan ? 'Your current plan' : 'Included with every account'; ?></span>
                    <?php elseif (!$isAvailable): ?>
                        <button class="btn btn-secondary" type="button" disabled>Unavailable for new checkouts</button>
                    <?php elseif ($providerConfigured && $planPrice !== null): ?>
                        <a class="btn <?= $isCurrentPlan ? 'btn-secondary' : 'btn-primary'; ?> billing-checkout-link" href="subscription_checkout.php?plan=<?= rawurlencode($planCode); ?>&amp;cycle=<?= rawurlencode($cycle); ?>">
                            <?= $isCurrentPlan ? 'Renew ' . e($plan['name']) : 'Checkout'; ?>
                        </a>
                    <?php else: ?>
                        <button class="btn btn-secondary" type="button" disabled>
                            <?= $planPrice === null ? 'Not offered in this cycle' : 'Checkout unavailable'; ?>
                        </button>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="billing-fine-print">
            <p>Paid access starts after payment is confirmed. Plans are prepaid, do not auto-renew, and return to Free when their period ends. USD prices are rounded international equivalents.</p>
            <p>Early renewal extends the current plan. A plan switch takes effect after confirmed payment; unused time is not refunded. Business currently provides higher usage limits only.</p>
        </div>
    </section>

    <section aria-labelledby="billing-history-heading">
        <div class="section-header">
            <span class="eyebrow">Payments</span>
            <h2 id="billing-history-heading">Billing history</h2>
            <p><?= e($paymentMethodsSummary); ?> transactions appear here after they are recorded.</p>
        </div>
        <?php if ($billingHistory): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Description</th><th>Period</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($billingHistory as $transaction): ?>
                            <tr>
                                <td><?= e((string) ($transaction['paid_at'] ?: $transaction['created_at'])); ?></td>
                                <td><?= e((string) ($transaction['description'] ?: 'Subscription payment')); ?></td>
                                <td><?= e(ucfirst((string) $transaction['billing_cycle'])); ?></td>
                                <td><?= e(formatMoney((float) $transaction['amount'], (string) $transaction['currency'])); ?></td>
                                <?php
                                $transactionStatus = strtolower((string) $transaction['status']);
                                $statusClass = match ($transactionStatus) {
                                    'paid', 'completed', 'confirmed' => 'success',
                                    'pending', 'processing' => 'pending',
                                    'failed', 'cancelled', 'expired' => 'failed',
                                    default => 'other',
                                };
                                ?>
                                <td><span class="billing-status billing-status-<?= e($statusClass); ?>"><?= e(ucfirst($transactionStatus)); ?></span></td>
                                <td class="billing-history-actions">
                                    <?php
                                    $checkoutUrl = (string) ($transaction['checkout_url'] ?? '');
                                    $checkoutParts = parse_url($checkoutUrl);
                                    $checkoutDomain = $transaction['provider'] === 'fapshi' ? 'fapshi.com' : 'nowpayments.io';
                                    $safeCheckoutUrl = $transaction['status'] === 'pending'
                                        && ($checkoutParts['scheme'] ?? '') === 'https'
                                        && isset($checkoutParts['host'])
                                        && (strtolower($checkoutParts['host']) === $checkoutDomain
                                            || str_ends_with(strtolower($checkoutParts['host']), '.' . $checkoutDomain));
                                    ?>
                                    <?php if ($safeCheckoutUrl): ?><a class="billing-resume-checkout" href="<?= e($checkoutUrl); ?>" target="_blank" rel="noopener noreferrer">Resume checkout</a><?php endif; ?>
                                    <?php if ($transactionStatus === 'pending'): ?>
                                        <form method="post" class="billing-cancel-pending-form">
                                            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                                            <input type="hidden" name="action" value="cancel_pending_checkout">
                                            <input type="hidden" name="transaction_id" value="<?= (int) $transaction['id']; ?>">
                                            <button class="btn btn-secondary billing-cancel-pending" type="submit">Cancel payment</button>
                                            <small>If you already paid, check payment status instead. This does not cancel a payment at the provider.</small>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($transaction['provider'] === 'fapshi' && $transaction['status'] === 'pending'): ?>
                                        <form method="post" class="billing-check-payment-form">
                                            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                                            <input type="hidden" name="action" value="check_fapshi">
                                            <input type="hidden" name="transaction_id" value="<?= (int) $transaction['id']; ?>">
                                            <button class="btn btn-secondary billing-check-payment" type="submit">Check payment</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="notice billing-empty-state">No subscription payments yet. Your confirmed payments will appear here.</p>
        <?php endif; ?>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
