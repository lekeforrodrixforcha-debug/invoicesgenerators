<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/subscription_payments.php';
requireAuth($db);

$userId = (int) $_SESSION['user_id'];
$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}

$planCode = (string) ($_POST['plan'] ?? $_GET['plan'] ?? '');
$cycle = (string) ($_POST['cycle'] ?? $_GET['cycle'] ?? '');
$catalog = subscriptionPlanCatalog($db);
$validCheckout = isset($catalog[$planCode]) && $planCode !== 'free'
    && !empty($catalog[$planCode]['is_available'])
    && (($planCode === 'pro' && $cycle === 'monthly') || ($planCode === 'business' && $cycle === 'yearly'));
if (!$validCheckout) {
    redirect('billing.php');
}

$isCameroon = isCameroonCountry((string) ($business['country'] ?? ''));
$checkoutUser = getCurrentUser($db);
$activeAnnouncements = [];
if (($checkoutUser['role'] ?? 'user') !== 'admin') {
    $announcementQuery = $db->prepare('SELECT title, message FROM announcements WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= :now) AND (ends_at IS NULL OR ends_at >= :now) ORDER BY created_at DESC, id DESC LIMIT 3');
    $announcementQuery->execute(['now' => gmdate('Y-m-d H:i:s')]);
    $activeAnnouncements = $announcementQuery->fetchAll();
}
$paymentMethods = subscriptionPaymentMethodsForCountry((string) ($business['country'] ?? ''));
$paymentMethod = (string) ($_POST['payment_method'] ?? '');
$promoCodeInput = trim((string) ($_POST['promo_code'] ?? ''));
$promoQuotes = [];
$promoErrors = [];
$promoMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        if (($_POST['action'] ?? '') === 'apply_promo') {
            if ($promoCodeInput === '') {
                throw new InvalidArgumentException('Enter a promo code to apply.');
            }
            $validQuotes = 0;
            foreach ($paymentMethods as $methodCode) {
                $promoCurrency = $methodCode === 'momo' ? 'XAF' : 'USD';
                $listAmount = subscriptionPrice($catalog[$planCode], $cycle, $promoCurrency);
                if ($listAmount !== null && $listAmount > 0) {
                    try {
                        $promoQuotes[$methodCode] = quotePromoCode($db, $promoCodeInput, $listAmount, $promoCurrency, $userId);
                        $validQuotes++;
                    } catch (InvalidArgumentException $exception) {
                        $promoErrors[$methodCode] = $exception->getMessage();
                    }
                }
            }
            if ($validQuotes === 0) {
                throw new InvalidArgumentException(reset($promoErrors) ?: 'Enter a promo code to apply.');
            }
            $promoMessage = 'Promo applied. Each payment option shows its currency-specific price.';
        } else {
            $checkoutUrl = createSubscriptionCheckout(
                $db,
                $userId,
                $planCode,
                $cycle,
                $paymentMethod,
                getCurrentUser($db) ?? [],
                $business,
                $promoCodeInput
            );
            header('Location: ' . $checkoutUrl, true, 303);
            exit;
        }
    } catch (Throwable $exception) {
        if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
            $errorMessage = $exception->getMessage();
        } else {
            error_log('Subscription checkout failed for user ' . $userId . ': ' . $exception->getMessage());
            $errorMessage = 'Checkout could not be started. Please try again.';
        }
    }
}

$plan = $catalog[$planCode];
$amounts = [
    'momo' => subscriptionPrice($plan, $cycle, 'XAF'),
    'crypto' => subscriptionPrice($plan, $cycle, 'USD'),
];
$methods = [
    'momo' => [
        'name' => 'Mobile Money',
        'currency' => 'XAF',
        'amount' => $amounts['momo'],
        'description' => 'Pay with MTN MoMo or Orange Money.',
        'configured' => subscriptionPaymentProviderConfigured('XAF'),
        'symbol' => 'M',
    ],
    'crypto' => [
        'name' => 'Cryptocurrency',
        'currency' => 'USD',
        'amount' => $amounts['crypto'],
        'description' => 'Pay with a supported cryptocurrency.',
        'configured' => subscriptionPaymentProviderConfigured('USD'),
        'symbol' => '₿',
    ],
];

$siteTitle = 'Choose payment method | Invoices Generators';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($siteTitle); ?></title>
    <link rel="stylesheet" href="<?= e(publicAssetUrl('public/css/styles.css')); ?>">
</head>
<body class="subscription-checkout-body">
<main class="subscription-checkout-page">
    <header class="subscription-checkout-header">
        <a class="brand-link subscription-checkout-brand" href="billing.php">
            <span class="brand-mark" aria-hidden="true">I</span>
            <span>Invoices Gen</span>
        </a>
        <a class="subscription-checkout-back" href="billing.php">Back to plans</a>
    </header>

    <section class="subscription-checkout-content" aria-labelledby="checkout-title">
        <div class="subscription-checkout-heading">
            <span class="eyebrow">SECURE CHECKOUT</span>
            <h1 id="checkout-title">Choose how to pay</h1>
            <p>
                <?= $isCameroon
                    ? 'Choose Mobile Money in FCFA or cryptocurrency in USD.'
                    : 'International checkout is charged in USD through cryptocurrency.'; ?>
            </p>
        </div>

        <?php if ($errorMessage): ?>
            <div class="notice notice-error" role="alert">
                <?= e($errorMessage); ?>
                <?php if (str_contains($errorMessage, 'pending payment')): ?>
                    <a href="billing.php#billing-history-heading">Go to Billing history</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($promoMessage): ?><div class="notice notice-success" role="status"><?= e($promoMessage); ?></div><?php endif; ?>
        <?php if ($activeAnnouncements): ?>
            <aside class="user-announcements checkout-announcements" aria-label="Platform announcements">
                <?php foreach ($activeAnnouncements as $announcement): ?>
                    <article><strong><?= e($announcement['title']); ?></strong><p><?= e($announcement['message']); ?></p></article>
                <?php endforeach; ?>
            </aside>
        <?php endif; ?>

        <section class="subscription-order-summary" aria-label="Order summary">
            <div class="subscription-order-icon" aria-hidden="true"><?= e(strtoupper(substr($planCode, 0, 1))); ?></div>
            <div class="subscription-order-details">
                <span>SUBSCRIPTION</span>
                <strong><?= e($plan['name']); ?> plan</strong>
                <small><?= $cycle === 'yearly' ? 'One year · prepaid' : 'One month · prepaid'; ?></small>
            </div>
            <div class="subscription-order-country">
                <span>Billing region</span>
                <strong><?= e($isCameroon ? 'Cameroon' : 'International'); ?></strong>
            </div>
        </section>

        <form method="post" class="subscription-payment-methods">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <input type="hidden" name="plan" value="<?= e($planCode); ?>">
            <input type="hidden" name="cycle" value="<?= e($cycle); ?>">
            <div class="subscription-promo-entry">
                <label for="promo-code">Promo code</label>
                <div class="subscription-promo-controls">
                    <input id="promo-code" name="promo_code" type="text" maxlength="40" value="<?= e($promoCodeInput); ?>" autocomplete="off" placeholder="Enter code">
                    <button class="btn btn-secondary" type="submit" name="action" value="apply_promo">Apply code</button>
                </div>
                <small>Enter a code to see the discount before choosing how to pay.</small>
            </div>
            <?php foreach ($paymentMethods as $methodCode): ?>
                <?php
                $method = $methods[$methodCode];
                $price = $promoQuotes[$methodCode]['amount'] ?? $method['amount'];
                $methodAvailable = $method['configured'] && $price !== null && $price > 0;
                ?>
                <article class="subscription-payment-card <?= $paymentMethod === $methodCode ? 'is-selected' : ''; ?> <?= !$methodAvailable ? 'is-unavailable' : ''; ?>">
                    <div class="subscription-payment-card-top">
                        <span class="subscription-payment-symbol" aria-hidden="true"><?= e($method['symbol']); ?></span>
                        <span class="subscription-payment-provider">Secure payment</span>
                    </div>
                    <h2><?= e($method['name']); ?></h2>
                    <p><?= e($method['description']); ?></p>
                    <?php if (isset($promoQuotes[$methodCode])): ?><small class="subscription-promo-original"><del><?= e(number_format((float) $method['amount'], $method['currency'] === 'XAF' ? 0 : 2)); ?> <?= e($method['currency']); ?></del></small><?php endif; ?>
                    <?php if (isset($promoErrors[$methodCode])): ?><small class="subscription-promo-method-error"><?= e($promoErrors[$methodCode]); ?></small><?php endif; ?>
                    <strong class="subscription-payment-price">
                        <?php if ($price === null): ?>
                            Not offered
                        <?php elseif ($method['currency'] === 'USD'): ?>
                            $<?= e(number_format((float) $price, isset($promoQuotes[$methodCode]) ? 2 : 0)); ?>
                        <?php else: ?>
                            <?= e(number_format((float) $price, 0)); ?> FCFA
                        <?php endif; ?>
                    </strong>
                    <?php if ($methodAvailable): ?>
                        <button class="btn btn-primary" type="submit" name="payment_method" value="<?= e($methodCode); ?>">
                            Continue with <?= e($method['name']); ?>
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary" type="button" disabled>
                            <?= $price === null ? 'Not available for this plan' : 'Payment method unavailable'; ?>
                        </button>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </form>

        <p class="subscription-checkout-note">Your plan activates after the payment provider confirms your payment. Subscriptions are prepaid and do not renew automatically.</p>
    </section>
</main>
</body>
</html>
