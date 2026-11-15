<?php

function subscriptionPaymentBaseUrl(): string
{
    $baseUrl = rtrim(subscriptionPaymentSetting('APP_BASE_URL'), '/');
    $parts = parse_url($baseUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['https', 'http'], true)
        || (strtolower($parts['scheme']) !== 'https'
            && !in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true))) {
        throw new RuntimeException('Set APP_BASE_URL to the public HTTPS address of this app before enabling subscription checkout.');
    }

    return $baseUrl;
}

function subscriptionProviderRequest(string $url, array $headers, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for subscription payments.');
    }
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (!is_string($response)) {
        error_log('Subscription provider request failed: ' . $error);
        throw new RuntimeException('The payment provider could not be reached. No paid access was granted.');
    }
    try {
        $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('Subscription provider returned invalid JSON with HTTP status ' . $status . '.');
        throw new RuntimeException('The payment provider returned an unreadable response. No paid access was granted.');
    }
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        error_log('Subscription provider request returned HTTP status ' . $status . '.');
        throw new RuntimeException('The payment provider did not accept the checkout request. No paid access was granted.');
    }

    return $decoded;
}

function fapshiApiHost(): string
{
    $environment = strtolower(subscriptionPaymentSetting('FAPSHI_ENV'));
    if ($environment === 'sandbox') {
        return 'https://sandbox.fapshi.com';
    }
    if (in_array($environment, ['live', 'production'], true)) {
        return 'https://live.fapshi.com';
    }

    throw new RuntimeException('Set FAPSHI_ENV to live or production before enabling XAF checkout.');
}

function subscriptionPaymentDetails(string $paymentMethod): array
{
    $methods = [
        'momo' => ['provider' => 'fapshi', 'currency' => 'XAF'],
        'crypto' => ['provider' => 'nowpayments', 'currency' => 'USD'],
    ];
    if (!isset($methods[$paymentMethod])) {
        throw new InvalidArgumentException('Choose Mobile Money or cryptocurrency as the payment method.');
    }

    return $methods[$paymentMethod];
}

function subscriptionPaymentMethodsForCountry(?string $country): array
{
    return isCameroonCountry($country) ? ['momo', 'crypto'] : ['crypto'];
}

function quotePromoCode(PDO $pdo, string $code, float $amount, string $currency, ?int $excludePendingUserId = null): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['id' => null, 'code' => null, 'discount' => 0.0, 'amount' => $amount];
    }
    if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
        throw new InvalidArgumentException('Enter a valid promo code.');
    }

    $statement = $pdo->prepare('SELECT * FROM promo_codes WHERE UPPER(code) = :code LIMIT 1');
    $statement->execute(['code' => $code]);
    $promo = $statement->fetch();
    $now = gmdate('Y-m-d H:i:s');
    if (!$promo || (int) $promo['is_active'] !== 1
        || ($promo['expires_at'] !== null && $promo['expires_at'] < $now)) {
        throw new InvalidArgumentException('That promo code is invalid or expired.');
    }
    $promoCurrency = strtoupper((string) $promo['currency']);
    $paymentCurrency = strtoupper($currency);
    if ($promoCurrency !== $paymentCurrency && !($promoCurrency === 'BOTH' && $promo['discount_type'] === 'percent')) {
        if ($promoCurrency === 'BOTH') {
            throw new InvalidArgumentException('A fixed-amount promo must be set up separately for USD and XAF.');
        }
        throw new InvalidArgumentException('This promo code is for ' . $promoCurrency . ' checkout only.');
    }
    if ($promo['max_redemptions'] !== null) {
        $redemptions = $pdo->prepare("SELECT COUNT(*) FROM subscription_transactions WHERE promo_code_id = :promo_code_id AND (status = 'paid' OR (status = 'pending' AND (:exclude_user_id IS NULL OR user_id <> :exclude_user_id)))");
        $redemptions->execute([
            'promo_code_id' => $promo['id'],
            'exclude_user_id' => $excludePendingUserId,
        ]);
        if ((int) $redemptions->fetchColumn() >= (int) $promo['max_redemptions']) {
            throw new InvalidArgumentException('That promo code has reached its redemption limit.');
        }
    }

    $discount = $promo['discount_type'] === 'percent'
        ? $amount * ((float) $promo['discount_value'] / 100)
        : (float) $promo['discount_value'];
    $discount = round(min($amount, $discount), $currency === 'XAF' ? 0 : 2);
    if ($discount <= 0 || $discount >= $amount) {
        throw new InvalidArgumentException('That promo code cannot be applied to this plan.');
    }

    return [
        'id' => (int) $promo['id'],
        'code' => (string) $promo['code'],
        'discount' => $discount,
        'amount' => round($amount - $discount, $currency === 'XAF' ? 0 : 2),
    ];
}

function createSubscriptionCheckout(PDO $pdo, int $userId, string $planCode, string $cycle, string $paymentMethod, array $user, array $business, string $promoCode = ''): string
{
    $catalog = subscriptionPlanCatalog($pdo);
    if (!isset($catalog[$planCode]) || $planCode === 'free' || empty($catalog[$planCode]['is_available'])) {
        throw new InvalidArgumentException('Choose an available paid plan.');
    }
    if (($planCode === 'pro' && $cycle !== 'monthly') || ($planCode === 'business' && $cycle !== 'yearly')) {
        throw new InvalidArgumentException('Choose Pro monthly or Business yearly billing.');
    }
    $paymentDetails = subscriptionPaymentDetails($paymentMethod);
    if (!in_array($paymentMethod, subscriptionPaymentMethodsForCountry((string) ($business['country'] ?? '')), true)) {
        throw new InvalidArgumentException('That payment method is not available for this billing country.');
    }
    $provider = $paymentDetails['provider'];
    $currency = $paymentDetails['currency'];
    $listAmount = subscriptionPrice($catalog[$planCode], $cycle, $currency);
    if ($listAmount === null || $listAmount <= 0) {
        throw new InvalidArgumentException('The selected plan and billing period are not available with that payment method.');
    }
    $promo = quotePromoCode($pdo, $promoCode, $listAmount, $currency, $userId);
    $amount = $promo['amount'];
    if (!subscriptionPaymentProviderConfigured($currency)) {
        throw new RuntimeException('That payment method is not configured. Choose another method or contact the site owner.');
    }
    $baseUrl = subscriptionPaymentBaseUrl();
    $subscription = getUserSubscription($pdo, $userId);
    if (!$subscription) {
        throw new RuntimeException('The account subscription could not be loaded.');
    }
    $planStatement = $pdo->prepare('SELECT id FROM plans WHERE code = :code LIMIT 1');
    $planStatement->execute(['code' => $planCode]);
    $planId = $planStatement->fetchColumn();
    if ($planId === false) {
        throw new RuntimeException('The selected plan is missing from the plan catalog.');
    }
    $description = $catalog[$planCode]['name'] . ' ' . ucfirst($cycle) . ' subscription'
        . ($promo['code'] !== null ? ' (promo ' . $promo['code'] . ')' : '');

    $pdo->beginTransaction();
    try {
        if (databaseDriver($pdo) === 'mysql') {
            $lock = $pdo->prepare('SELECT id FROM subscriptions WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $subscription['id']]);
            $lock->fetchColumn();
        }
        $pendingSql = "SELECT * FROM subscription_transactions WHERE user_id = :user_id AND status = 'pending' ORDER BY id DESC LIMIT 1";
        if (databaseDriver($pdo) === 'mysql') {
            $pendingSql .= ' FOR UPDATE';
        }
        $pendingStatement = $pdo->prepare($pendingSql);
        $pendingStatement->execute(['user_id' => $userId]);
        $pendingTransaction = $pendingStatement->fetch();
        if ($pendingTransaction) {
            $pendingUrl = (string) ($pendingTransaction['checkout_url'] ?? '');
            if ($pendingUrl !== '' && (int) $pendingTransaction['plan_id'] === (int) $planId
                && $pendingTransaction['billing_cycle'] === $cycle && $pendingTransaction['currency'] === $currency
                && (int) ($pendingTransaction['promo_code_id'] ?? 0) === (int) ($promo['id'] ?? 0)) {
                $pdo->commit();
                return $pendingUrl;
            }
            $hasProviderRequest = trim((string) ($pendingTransaction['provider_transaction_id'] ?? '')) !== ''
                || $pendingUrl !== '';
            throw new RuntimeException($hasProviderRequest
                ? 'There is already a pending payment. Open Billing history to cancel this attempt, or resume/check its status if you have already paid.'
                : 'Your previous checkout did not get a payment link. Open Billing history and select Cancel payment, then try again.');
        }
        if ($promo['id'] !== null) {
            $promoLock = $pdo->prepare('UPDATE promo_codes SET is_active = is_active WHERE id = :id');
            $promoLock->execute(['id' => $promo['id']]);
            $promo = quotePromoCode($pdo, (string) $promo['code'], $listAmount, $currency);
            $amount = $promo['amount'];
            $description = $catalog[$planCode]['name'] . ' ' . ucfirst($cycle) . ' subscription (promo ' . $promo['code'] . ')';
        }
        $insert = $pdo->prepare('INSERT INTO subscription_transactions (subscription_id, user_id, plan_id, billing_cycle, provider, amount, currency, status, description, promo_code_id, promo_code, discount_amount) VALUES (:subscription_id, :user_id, :plan_id, :billing_cycle, :provider, :amount, :currency, :status, :description, :promo_code_id, :promo_code, :discount_amount)');
        $insert->execute([
            'subscription_id' => $subscription['id'],
            'user_id' => $userId,
            'plan_id' => (int) $planId,
            'billing_cycle' => $cycle,
            'provider' => $provider,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'description' => $description,
            'promo_code_id' => $promo['id'],
            'promo_code' => $promo['code'],
            'discount_amount' => $promo['discount'],
        ]);
        $transactionId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $externalId = 'subtx-' . $transactionId;
    if ($provider === 'fapshi') {
        $response = subscriptionProviderRequest(fapshiApiHost() . '/initiate-pay', [
            'Content-Type: application/json',
            'apiuser: ' . subscriptionPaymentSetting('FAPSHI_API_USER'),
            'apikey: ' . subscriptionPaymentSetting('FAPSHI_API_KEY'),
        ], [
            'amount' => (int) $amount,
            'email' => (string) ($user['email'] ?? ''),
            'redirectUrl' => $baseUrl . '/billing.php?payment=pending',
            'userId' => 'user-' . $userId,
            'externalId' => $externalId,
            'message' => $description,
        ]);
        $checkoutUrl = $response['link'] ?? null;
        $providerTransactionId = $response['transId'] ?? null;
        if (is_string($providerTransactionId) && $providerTransactionId !== '') {
            $saveProviderId = $pdo->prepare('UPDATE subscription_transactions SET provider_transaction_id = :provider_transaction_id WHERE id = :id AND status = :status');
            $saveProviderId->execute([
                'provider_transaction_id' => $providerTransactionId,
                'id' => $transactionId,
                'status' => 'pending',
            ]);
        }
    } else {
        $response = subscriptionProviderRequest('https://api.nowpayments.io/v1/invoice', [
            'Content-Type: application/json',
            'x-api-key: ' . subscriptionPaymentSetting('NOWPAYMENTS_API_KEY'),
        ], [
            'price_amount' => $amount,
            'price_currency' => 'usd',
            'order_id' => $externalId,
            'order_description' => $description,
            'ipn_callback_url' => $baseUrl . '/subscription_webhook.php?provider=nowpayments',
            'success_url' => $baseUrl . '/billing.php?payment=pending',
            'cancel_url' => $baseUrl . '/billing.php?payment=cancelled',
        ]);
        $checkoutUrl = $response['invoice_url'] ?? null;
        $providerTransactionId = isset($response['id']) ? (string) $response['id'] : null;
        if (is_string($providerTransactionId) && $providerTransactionId !== '') {
            $saveProviderId = $pdo->prepare('UPDATE subscription_transactions SET provider_transaction_id = :provider_transaction_id WHERE id = :id AND status = :status');
            $saveProviderId->execute([
                'provider_transaction_id' => $providerTransactionId,
                'id' => $transactionId,
                'status' => 'pending',
            ]);
        }
    }
    if (!is_string($checkoutUrl) || filter_var($checkoutUrl, FILTER_VALIDATE_URL) === false
        || !is_string($providerTransactionId) || $providerTransactionId === '') {
        error_log('Subscription provider returned an incomplete checkout response for local transaction ' . $transactionId . '.');
        throw new RuntimeException('The payment provider did not return a valid checkout link. The transaction remains pending for review.');
    }
    $checkoutParts = parse_url($checkoutUrl);
    $checkoutHost = strtolower((string) ($checkoutParts['host'] ?? ''));
    $expectedDomain = $provider === 'fapshi' ? 'fapshi.com' : 'nowpayments.io';
    if (($checkoutParts['scheme'] ?? '') !== 'https'
        || ($checkoutHost !== $expectedDomain && !str_ends_with($checkoutHost, '.' . $expectedDomain))) {
        error_log('Subscription provider returned an unexpected checkout host for local transaction ' . $transactionId . '.');
        throw new RuntimeException('The payment provider returned an unexpected checkout address. No paid access was granted.');
    }

    $update = $pdo->prepare('UPDATE subscription_transactions SET provider_transaction_id = :provider_transaction_id, checkout_url = :checkout_url WHERE id = :id AND status = :status');
    $update->execute([
        'provider_transaction_id' => $providerTransactionId,
        'checkout_url' => $checkoutUrl,
        'id' => $transactionId,
        'status' => 'pending',
    ]);

    return $checkoutUrl;
}

function cancelPendingSubscriptionCheckout(PDO $pdo, int $userId, int $transactionId): bool
{
    if ($userId < 1 || $transactionId < 1) {
        throw new InvalidArgumentException('Choose a valid pending checkout.');
    }

    $pdo->beginTransaction();
    try {
        $sql = "SELECT id, status FROM subscription_transactions WHERE id = :id AND user_id = :user_id";
        if (databaseDriver($pdo) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $transactionId, 'user_id' => $userId]);
        $transaction = $statement->fetch();
        if (!$transaction || $transaction['status'] !== 'pending') {
            $pdo->rollBack();
            return false;
        }
        $update = $pdo->prepare("UPDATE subscription_transactions SET status = 'cancelled' WHERE id = :id AND user_id = :user_id AND status = 'pending'");
        $update->execute(['id' => $transactionId, 'user_id' => $userId]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();

        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function sortedSubscriptionWebhookPayload(array $payload): array
{
    foreach ($payload as &$value) {
        if (is_array($value)) {
            $value = sortedSubscriptionWebhookPayload($value);
        }
    }
    unset($value);
    ksort($payload);

    return $payload;
}

function isVerifiedFapshiSubscriptionPayment(array $verified, array $transaction): bool
{
    return matchesFapshiSubscriptionTransaction($verified, $transaction)
        && (string) ($verified['transId'] ?? '') === (string) ($transaction['provider_transaction_id'] ?? '')
        && strtoupper((string) ($verified['status'] ?? '')) === 'SUCCESSFUL';
}

function matchesFapshiSubscriptionTransaction(array $verified, array $transaction): bool
{
    return (string) ($verified['transId'] ?? '') !== ''
        && (string) ($verified['externalId'] ?? '') === 'subtx-' . (int) $transaction['id']
        && (string) ($verified['userId'] ?? '') === 'user-' . (int) $transaction['user_id']
        && ($verified['transType'] ?? null) === 'Collection'
        && is_numeric($verified['amount'] ?? null)
        && (int) $verified['amount'] === (int) $transaction['amount'];
}

function checkFapshiSubscriptionTransaction(PDO $pdo, int $userId, int $transactionId): string
{
    $statement = $pdo->prepare('SELECT * FROM subscription_transactions WHERE id = :id AND user_id = :user_id AND provider = :provider LIMIT 1');
    $statement->execute([
        'id' => $transactionId,
        'user_id' => $userId,
        'provider' => 'fapshi',
    ]);
    $transaction = $statement->fetch();
    if (!$transaction) {
        throw new InvalidArgumentException('That Fapshi payment could not be found.');
    }
    if ($transaction['status'] !== 'pending') {
        return (string) $transaction['status'];
    }

    $providerTransactionId = trim((string) ($transaction['provider_transaction_id'] ?? ''));
    if ($providerTransactionId === '') {
        throw new RuntimeException('Fapshi has not returned a transaction ID yet. Contact support before trying another payment.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $checkedAt = $_SESSION['fapshi_status_checked_at'] ?? [];
        $lastCheck = (int) ($checkedAt[(string) $transactionId] ?? 0);
        if (time() - $lastCheck < 10) {
            throw new RuntimeException('Please wait a few seconds before checking this payment again.');
        }
        $checkedAt[(string) $transactionId] = time();
        $_SESSION['fapshi_status_checked_at'] = array_slice($checkedAt, -50, null, true);
    }

    $host = fapshiApiHost();
    $apiUser = subscriptionPaymentSetting('FAPSHI_API_USER');
    $apiKey = subscriptionPaymentSetting('FAPSHI_API_KEY');
    if ($apiUser === '' || $apiKey === '') {
        throw new RuntimeException('Fapshi status checks are not configured. Contact the site owner.');
    }
    $verified = subscriptionProviderRequest($host . '/payment-status/' . rawurlencode($providerTransactionId), [
        'apiuser: ' . $apiUser,
        'apikey: ' . $apiKey,
    ]);
    $verifiedTransaction = $transaction;
    $verifiedTransaction['provider_transaction_id'] = $providerTransactionId;
    $providerStatus = strtoupper((string) ($verified['status'] ?? ''));
    if ((string) ($verified['transId'] ?? '') !== $providerTransactionId
        || !matchesFapshiSubscriptionTransaction($verified, $transaction)) {
        error_log('Fapshi transaction verification did not match local subscription transaction ' . $transactionId . '.');
        throw new RuntimeException('Fapshi returned transaction details that do not match this payment. No plan change was made.');
    }

    if ($providerStatus === 'SUCCESSFUL') {
        if (!isVerifiedFapshiSubscriptionPayment($verified, $verifiedTransaction)
            || !activateSubscriptionFromTransaction($pdo, $transactionId, $providerTransactionId)) {
            $latest = $pdo->prepare('SELECT status FROM subscription_transactions WHERE id = :id AND user_id = :user_id');
            $latest->execute(['id' => $transactionId, 'user_id' => $userId]);
            if ($latest->fetchColumn() === 'paid') {
                return 'paid';
            }
            throw new RuntimeException('The payment could not be activated. Contact support before paying again.');
        }
        return 'paid';
    }
    if (in_array($providerStatus, ['FAILED', 'EXPIRED'], true)) {
        $pdo->prepare("UPDATE subscription_transactions SET status = :status WHERE id = :id AND user_id = :user_id AND status = 'pending'")
            ->execute([
                'status' => strtolower($providerStatus),
                'id' => $transactionId,
                'user_id' => $userId,
            ]);
        return strtolower($providerStatus);
    }
    if (in_array($providerStatus, ['CREATED', 'PENDING'], true)) {
        return 'pending';
    }

    error_log('Fapshi returned unknown payment status for local subscription transaction ' . $transactionId . '.');
    throw new RuntimeException('Fapshi returned an unrecognized payment status. No plan change was made.');
}

function subscriptionPeriodEnd(DateTimeImmutable $periodStart, string $cycle): DateTimeImmutable
{
    if (!in_array($cycle, ['monthly', 'yearly'], true)) {
        throw new InvalidArgumentException('The subscription billing period is invalid.');
    }
    $months = $cycle === 'yearly' ? 12 : 1;
    $targetMonth = $periodStart->modify('first day of this month')->modify('+' . $months . ' months');
    $day = min((int) $periodStart->format('d'), (int) $targetMonth->format('t'));

    return $targetMonth->setDate(
        (int) $targetMonth->format('Y'),
        (int) $targetMonth->format('m'),
        $day
    )->setTime(
        (int) $periodStart->format('H'),
        (int) $periodStart->format('i'),
        (int) $periodStart->format('s')
    );
}

function activateSubscriptionFromTransaction(PDO $pdo, int $transactionId, string $providerTransactionId): bool
{
    $pdo->beginTransaction();
    try {
        $sql = 'SELECT t.*, s.plan_id AS previous_plan_id, s.current_period_end FROM subscription_transactions t JOIN subscriptions s ON s.id = t.subscription_id WHERE t.id = :id';
        if (databaseDriver($pdo) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $transactionId]);
        $transaction = $statement->fetch();
        if (!$transaction || $transaction['status'] !== 'pending' || (int) ($transaction['plan_id'] ?? 0) < 1) {
            $pdo->rollBack();
            return false;
        }
        $currentProviderTransactionId = (string) ($transaction['provider_transaction_id'] ?? '');
        if ($currentProviderTransactionId !== '' && $currentProviderTransactionId !== $providerTransactionId
            && $transaction['provider'] === 'fapshi') {
            $pdo->rollBack();
            return false;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $periodStart = $now;
        if ((int) $transaction['previous_plan_id'] === (int) $transaction['plan_id']
            && !empty($transaction['current_period_end'])) {
            $existingEnd = new DateTimeImmutable((string) $transaction['current_period_end'], new DateTimeZone('UTC'));
            if ($existingEnd > $periodStart) {
                $periodStart = $existingEnd;
            }
        }
        $periodEnd = subscriptionPeriodEnd($periodStart, (string) $transaction['billing_cycle']);
        $periodStartText = $periodStart->format('Y-m-d H:i:s');
        $periodEndText = $periodEnd->format('Y-m-d H:i:s');
        $paidAt = $now->format('Y-m-d H:i:s');
        $previousPlanId = (int) $transaction['previous_plan_id'];
        $newPlanId = (int) $transaction['plan_id'];

        $updateSubscription = $pdo->prepare('UPDATE subscriptions SET plan_id = :plan_id, status = :status, current_period_start = :period_start, current_period_end = :period_end, cancel_at_period_end = 0, provider = :provider, provider_subscription_id = NULL, updated_at = :updated_at WHERE id = :id');
        $updateSubscription->execute([
            'plan_id' => $newPlanId,
            'status' => 'active',
            'period_start' => $periodStartText,
            'period_end' => $periodEndText,
            'provider' => $transaction['provider'],
            'updated_at' => $paidAt,
            'id' => $transaction['subscription_id'],
        ]);
        $updateTransaction = $pdo->prepare('UPDATE subscription_transactions SET provider_transaction_id = :provider_transaction_id, status = :status, paid_at = :paid_at WHERE id = :id AND status = :pending_status');
        $updateTransaction->execute([
            'provider_transaction_id' => $providerTransactionId,
            'status' => 'paid',
            'paid_at' => $paidAt,
            'id' => $transactionId,
            'pending_status' => 'pending',
        ]);
        if ($updateTransaction->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $event = $pdo->prepare('INSERT INTO subscription_events (subscription_id, user_id, event_type, previous_plan_id, new_plan_id, details_json) VALUES (:subscription_id, :user_id, :event_type, :previous_plan_id, :new_plan_id, :details_json)');
        $event->execute([
            'subscription_id' => $transaction['subscription_id'],
            'user_id' => $transaction['user_id'],
            'event_type' => $previousPlanId === $newPlanId ? 'subscription_renewed' : 'plan_changed',
            'previous_plan_id' => $previousPlanId,
            'new_plan_id' => $newPlanId,
            'details_json' => json_encode([
                'provider' => $transaction['provider'],
                'billing_cycle' => $transaction['billing_cycle'],
                'amount' => (float) $transaction['amount'],
                'currency' => $transaction['currency'],
            ], JSON_THROW_ON_ERROR),
        ]);
        $pdo->commit();

        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function cancelSubscriptionAtPeriodEnd(PDO $pdo, int $userId): bool
{
    $subscription = getUserSubscription($pdo, $userId);
    if (!$subscription || $subscription['plan_code'] === 'free' || empty($subscription['current_period_end'])
        || (int) $subscription['cancel_at_period_end'] === 1) {
        return false;
    }

    $now = gmdate('Y-m-d H:i:s');
    $update = $pdo->prepare('UPDATE subscriptions SET cancel_at_period_end = 1, updated_at = :updated_at WHERE id = :id AND cancel_at_period_end = 0');
    $update->execute(['updated_at' => $now, 'id' => $subscription['id']]);
    if ($update->rowCount() !== 1) {
        return false;
    }
    $event = $pdo->prepare("INSERT INTO subscription_events (subscription_id, user_id, event_type, previous_plan_id, new_plan_id) VALUES (:subscription_id, :user_id, 'subscription_cancelled', :plan_id, :plan_id)");
    $event->execute(['subscription_id' => $subscription['id'], 'user_id' => $userId, 'plan_id' => $subscription['plan_id']]);

    return true;
}
