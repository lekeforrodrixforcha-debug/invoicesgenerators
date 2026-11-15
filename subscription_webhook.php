<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/subscription_payments.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respondSubscriptionWebhook(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respondSubscriptionWebhook(405, 'POST required.');
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 65536) {
    respondSubscriptionWebhook(413, 'Invalid webhook request size.');
}
try {
    $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    respondSubscriptionWebhook(400, 'Invalid JSON payload.');
}
if (!is_array($payload)) {
    respondSubscriptionWebhook(400, 'Invalid webhook payload.');
}

$provider = strtolower(trim((string) ($_GET['provider'] ?? '')));
try {
    if ($provider === 'fapshi') {
        $webhookSecret = subscriptionPaymentSetting('FAPSHI_WEBHOOK_SECRET');
        $providedSecret = (string) ($_SERVER['HTTP_X_WH_SECRET'] ?? '');
        if ($webhookSecret === '' || $providedSecret === '' || !hash_equals($webhookSecret, $providedSecret)) {
            respondSubscriptionWebhook(403, 'Webhook authentication failed.');
        }
        $transactionId = trim((string) ($payload['transId'] ?? ''));
        if ($transactionId === '') {
            respondSubscriptionWebhook(400, 'Fapshi transaction ID is missing.');
        }
        $find = $db->prepare('SELECT * FROM subscription_transactions WHERE provider = :provider AND provider_transaction_id = :provider_transaction_id LIMIT 1');
        $find->execute(['provider' => 'fapshi', 'provider_transaction_id' => $transactionId]);
        $transaction = $find->fetch();
        if (!$transaction || $transaction['status'] !== 'pending') {
            respondSubscriptionWebhook(200, 'No pending transaction requires an update.');
        }
        $verified = subscriptionProviderRequest(fapshiApiHost() . '/payment-status/' . rawurlencode($transactionId), [
            'apiuser: ' . subscriptionPaymentSetting('FAPSHI_API_USER'),
            'apikey: ' . subscriptionPaymentSetting('FAPSHI_API_KEY'),
        ]);
        if ((string) ($verified['transId'] ?? '') !== $transactionId
            || !matchesFapshiSubscriptionTransaction($verified, $transaction)
            || strtoupper((string) ($verified['status'] ?? '')) !== 'SUCCESSFUL') {
            $providerStatus = strtoupper((string) ($verified['status'] ?? ''));
            if (matchesFapshiSubscriptionTransaction($verified, $transaction)
                && in_array($providerStatus, ['FAILED', 'EXPIRED'], true)) {
                $db->prepare("UPDATE subscription_transactions SET status = :status WHERE id = :id AND status = 'pending'")
                    ->execute(['status' => strtolower($providerStatus), 'id' => $transaction['id']]);
            }
            respondSubscriptionWebhook(200, 'Payment is not confirmed.');
        }
        if (!activateSubscriptionFromTransaction($db, (int) $transaction['id'], $transactionId)) {
            respondSubscriptionWebhook(200, 'Transaction was already processed.');
        }
        respondSubscriptionWebhook(200, 'Subscription activated.');
    }

    if ($provider === 'nowpayments') {
        $secret = subscriptionPaymentSetting('NOWPAYMENTS_IPN_SECRET');
        $signature = strtolower(trim((string) ($_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? '')));
        $canonical = json_encode(sortedSubscriptionWebhookPayload($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $expectedSignature = $secret === '' ? '' : hash_hmac('sha512', $canonical, $secret);
        if ($expectedSignature === '' || $signature === '' || !hash_equals($expectedSignature, $signature)) {
            respondSubscriptionWebhook(403, 'Webhook authentication failed.');
        }
        $orderId = (string) ($payload['order_id'] ?? '');
        if (!preg_match('/^subtx-([1-9][0-9]*)$/', $orderId, $orderMatch)) {
            respondSubscriptionWebhook(400, 'Subscription order ID is invalid.');
        }
        $find = $db->prepare('SELECT * FROM subscription_transactions WHERE id = :id AND provider = :provider LIMIT 1');
        $find->execute(['id' => (int) $orderMatch[1], 'provider' => 'nowpayments']);
        $transaction = $find->fetch();
        if (!$transaction || $transaction['status'] !== 'pending') {
            respondSubscriptionWebhook(200, 'No pending transaction requires an update.');
        }
        if (strtoupper((string) ($payload['price_currency'] ?? '')) !== 'USD'
            || !is_numeric($payload['price_amount'] ?? null)
            || abs((float) $payload['price_amount'] - (float) $transaction['amount']) >= 0.01) {
            respondSubscriptionWebhook(400, 'Payment amount or currency does not match the order.');
        }
        $paymentStatus = strtolower((string) ($payload['payment_status'] ?? ''));
        if (in_array($paymentStatus, ['failed', 'expired'], true)) {
            $db->prepare('UPDATE subscription_transactions SET status = :status WHERE id = :id AND status = :pending')
                ->execute(['status' => $paymentStatus, 'id' => $transaction['id'], 'pending' => 'pending']);
            respondSubscriptionWebhook(200, 'Payment did not complete.');
        }
        if ($paymentStatus !== 'finished') {
            respondSubscriptionWebhook(200, 'Payment is not complete.');
        }
        $paymentId = trim((string) ($payload['payment_id'] ?? ''));
        if ($paymentId === '' || !activateSubscriptionFromTransaction($db, (int) $transaction['id'], $paymentId)) {
            respondSubscriptionWebhook(200, 'Transaction was already processed or is incomplete.');
        }
        respondSubscriptionWebhook(200, 'Subscription activated.');
    }
    respondSubscriptionWebhook(400, 'Unsupported payment provider.');
} catch (Throwable $exception) {
    error_log('Subscription webhook processing failed: ' . $exception->getMessage());
    respondSubscriptionWebhook(503, 'Webhook processing failed; no paid access was granted.');
}
