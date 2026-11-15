<?php

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function publicAssetVersion(string $path): string
{
    if (!preg_match('#\Apublic/[A-Za-z0-9._/-]+\z#D', $path) || str_contains($path, '..')) {
        throw new InvalidArgumentException('Public asset paths must reference a file inside the public directory.');
    }

    $assetPath = dirname(__DIR__) . '/' . $path;
    if (!is_file($assetPath)) {
        throw new RuntimeException('Public asset file does not exist: ' . $path);
    }

    $modifiedAt = filemtime($assetPath);
    if ($modifiedAt === false) {
        throw new RuntimeException('Unable to read public asset modification time: ' . $path);
    }

    $fileSize = filesize($assetPath);
    if ($fileSize === false) {
        throw new RuntimeException('Unable to read public asset size: ' . $path);
    }

    return $modifiedAt . '-' . $fileSize;
}

function publicAssetUrl(string $path): string
{
    return $path . '?v=' . publicAssetVersion($path);
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function setFlash(string $key, string $message): void
{
    $_SESSION['flash'][$key] = $message;
}

function getFlash(string $key): ?string
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $message = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $message;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        throw new InvalidArgumentException('Invalid CSRF token. Please refresh the page and try again.');
    }
}

function formatMoney(float $amount, string $currency = 'USD'): string
{
    $amount = (float) $amount;
    $currency = strtoupper($currency ?: 'USD');

    $decimals = $currency === 'XAF' ? 0 : 2;

    return $currency . ' ' . number_format($amount, $decimals, '.', ',');
}

function calculateLineTotal(float $quantity, float $unitPrice, float $discount = 0.0, float $taxRate = 0.0): float
{
    $subTotal = ($quantity * $unitPrice) - $discount;
    $taxAmount = ($subTotal * $taxRate) / 100;

    return round($subTotal + $taxAmount, 2);
}

function invoiceTotals(array $items): array
{
    $subtotal = 0.0;
    $discount = 0.0;
    $tax = 0.0;

    foreach ($items as $item) {
        $quantity = (float) ($item['quantity'] ?? 1);
        $unitPrice = (float) ($item['unit_price'] ?? 0);
        $lineDiscount = (float) ($item['discount'] ?? 0);
        $taxRate = (float) ($item['tax_rate'] ?? 0);

        $lineSubtotal = ($quantity * $unitPrice) - $lineDiscount;
        $subtotal += $lineSubtotal;
        $discount += $lineDiscount;
        $tax += ($lineSubtotal * $taxRate) / 100;
    }

    $total = round($subtotal + $tax, 2);

    return [
        'subtotal' => round($subtotal, 2),
        'discount' => round($discount, 2),
        'tax' => round($tax, 2),
        'total' => round($total, 2),
    ];
}

function isValidInvoiceDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function saveBusinessLogoUpload(array $upload, int $businessId): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'The logo exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The logo file is too large.',
            UPLOAD_ERR_PARTIAL => 'The logo upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server could not receive the logo upload.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the logo.',
            UPLOAD_ERR_EXTENSION => 'The server blocked the logo upload.',
        ];
        $errorCode = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        throw new RuntimeException($uploadErrors[$errorCode] ?? 'The logo could not be uploaded.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $fileSize = (int) ($upload['size'] ?? 0);
    if ($businessId < 1 || $fileSize < 1 || $fileSize > 3 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('Choose a logo image smaller than 3 MB.');
    }

    $imageInfo = getimagesize($temporaryPath);
    $imageType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    if ($imageInfo === false || !isset($extensions[$imageType]) || ($imageInfo['mime'] ?? '') !== $imageType) {
        throw new InvalidArgumentException('Use a valid PNG, JPG or WebP logo image.');
    }

    $width = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];
    if ($width < 1 || $height < 1 || $width > 10000 || $height > 10000 || $width * $height > 30000000) {
        throw new InvalidArgumentException('The logo image dimensions are too large.');
    }

    $relativeDirectory = 'storage/logos/' . $businessId;
    $absoluteDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logos' . DIRECTORY_SEPARATOR . $businessId;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('The server could not prepare logo storage.');
    }

    $filename = 'logo-' . bin2hex(random_bytes(16)) . '.' . $extensions[$imageType];
    $relativePath = $relativeDirectory . '/' . $filename;
    $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new RuntimeException('The server could not save the uploaded logo.');
    }

    return $relativePath;
}

function resolveBusinessLogoPath(?string $logoPath, int $businessId): ?string
{
    if ($logoPath === null || !preg_match('~^storage/logos/([1-9][0-9]*)/logo-[a-f0-9]{32}\.(?:png|jpg|webp)$~', $logoPath, $matches)) {
        return null;
    }

    if ((int) $matches[1] !== $businessId) {
        return null;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath);

    return is_file($absolutePath) ? $absolutePath : null;
}

function saveExpenseReceiptUpload(array $upload, int $businessId): ?string
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The receipt upload did not complete. Please try again.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $fileSize = (int) ($upload['size'] ?? 0);
    if ($businessId < 1 || $fileSize < 1 || $fileSize > 5 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('Choose a receipt file smaller than 5 MB.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
        throw new InvalidArgumentException('Use a PDF, JPG, PNG or WebP receipt file.');
    }
    if (str_starts_with($mimeType, 'image/')) {
        $imageInfo = getimagesize($temporaryPath);
        if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== $mimeType
            || (int) $imageInfo[0] < 1 || (int) $imageInfo[1] < 1
            || (int) $imageInfo[0] > 10000 || (int) $imageInfo[1] > 10000
            || (int) $imageInfo[0] * (int) $imageInfo[1] > 30000000) {
            throw new InvalidArgumentException('The receipt image is invalid or too large to process.');
        }
    } elseif (file_get_contents($temporaryPath, false, null, 0, 5) !== '%PDF-') {
        throw new InvalidArgumentException('The uploaded PDF receipt is invalid.');
    }

    $relativeDirectory = 'storage/expense-receipts/' . $businessId;
    $absoluteDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'expense-receipts' . DIRECTORY_SEPARATOR . $businessId;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('The server could not prepare receipt storage.');
    }

    $filename = 'receipt-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    $relativePath = $relativeDirectory . '/' . $filename;
    $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new RuntimeException('The server could not save the receipt.');
    }

    return $relativePath;
}

function createCustomerPortalToken(PDO $pdo, string $documentType, int $documentId, int $businessId): string
{
    $documents = [
        'invoice' => ['invoices', 'customer_portal_tokens', 'invoice_id'],
        'quotation' => ['quotations', 'quotation_portal_tokens', 'quotation_id'],
    ];
    if (!isset($documents[$documentType]) || $documentId < 1 || $businessId < 1) {
        throw new InvalidArgumentException('Choose a valid document for the customer link.');
    }

    [$documentTable, $tokenTable, $idColumn] = $documents[$documentType];
    $owner = $pdo->prepare("SELECT id, status FROM {$documentTable} WHERE id = :id AND business_id = :business_id");
    $owner->execute(['id' => $documentId, 'business_id' => $businessId]);
    $document = $owner->fetch();
    if (!$document) {
        throw new InvalidArgumentException('Document not found.');
    }
    $isInvalidPublicState = $document['status'] === 'Draft'
        || ($documentType === 'invoice' && $document['status'] === 'Cancelled')
        || ($documentType === 'quotation' && in_array($document['status'], ['Rejected', 'Expired', 'Converted'], true));
    if (!$document || $isInvalidPublicState) {
        throw new InvalidArgumentException('Issue the document before creating a customer link.');
    }

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $upsert = databaseDriver($pdo) === 'mysql'
        ? "INSERT INTO {$tokenTable} ({$idColumn}, token_hash, revoked_at) VALUES (:document_id, :token_hash, NULL) ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), created_at = CURRENT_TIMESTAMP, revoked_at = NULL"
        : "INSERT INTO {$tokenTable} ({$idColumn}, token_hash, revoked_at) VALUES (:document_id, :token_hash, NULL) ON CONFLICT({$idColumn}) DO UPDATE SET token_hash = excluded.token_hash, created_at = CURRENT_TIMESTAMP, revoked_at = NULL";
    $statement = $pdo->prepare($upsert);
    $statement->execute(['document_id' => $documentId, 'token_hash' => hash('sha256', $token)]);

    return $token;
}

function isValidCustomerPortalToken(string $token): bool
{
    return preg_match('/\A(?:[a-f0-9]{64}|[A-Za-z0-9_-]{43})\z/', $token) === 1;
}

function customerPortalPath(string $token, bool $pdf = false): string
{
    if (!isValidCustomerPortalToken($token)) {
        throw new InvalidArgumentException('Invalid customer portal token.');
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = str_replace('\\', '/', dirname($scriptName));
    $basePath = $basePath === '.' ? '' : rtrim($basePath, '/');

    return $basePath . '/p/' . rawurlencode($token) . ($pdf ? '/pdf' : '');
}

function revokeCustomerPortalToken(PDO $pdo, string $documentType, int $documentId, int $businessId): bool
{
    $documents = [
        'invoice' => ['invoices', 'customer_portal_tokens', 'invoice_id'],
        'quotation' => ['quotations', 'quotation_portal_tokens', 'quotation_id'],
    ];
    if (!isset($documents[$documentType]) || $documentId < 1 || $businessId < 1) {
        throw new InvalidArgumentException('Choose a valid document for the customer link.');
    }

    [$documentTable, $tokenTable, $idColumn] = $documents[$documentType];
    $owner = $pdo->prepare("SELECT id FROM {$documentTable} WHERE id = :id AND business_id = :business_id");
    $owner->execute(['id' => $documentId, 'business_id' => $businessId]);
    if (!$owner->fetchColumn()) {
        throw new InvalidArgumentException('Document not found.');
    }

    $statement = $pdo->prepare("UPDATE {$tokenTable} SET revoked_at = CURRENT_TIMESTAMP WHERE {$idColumn} = :document_id AND revoked_at IS NULL");
    $statement->execute(['document_id' => $documentId]);

    return $statement->rowCount() === 1;
}

function getCurrentUser(PDO $pdo): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);

    return $stmt->fetch() ?: null;
}

function getCurrentBusiness(PDO $pdo): ?array
{
    $user = getCurrentUser($pdo);
    if (!$user) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM businesses WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
    $stmt->execute(['user_id' => $user['id']]);

    $business = $stmt->fetch();
    if (!$business) {
        return null;
    }
    $business['currency'] = 'USD';

    return $business;
}

function requireAuth(PDO $pdo): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }

    if (!getCurrentUser($pdo)) {
        session_unset();
        redirect('login.php');
    }
}

function requireAdmin(PDO $pdo): array
{
    requireAuth($pdo);
    $user = getCurrentUser($pdo);
    if (!$user || ($user['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }

    return $user;
}

function consumeDailyAiUsage(PDO $pdo, int $userId, int $dailyLimit, ?string $usageDate = null): bool
{
    if ($userId < 1 || $dailyLimit < 1) {
        throw new InvalidArgumentException('A valid user and AI usage limit are required.');
    }

    $usageDate ??= gmdate('Y-m-d');
    if (!isValidInvoiceDate($usageDate)) {
        throw new InvalidArgumentException('A valid AI usage date is required.');
    }

    if (databaseDriver($pdo) === 'mysql') {
        $statement = $pdo->prepare('INSERT INTO ai_usage (user_id, usage_date, request_count) VALUES (:user_id, :usage_date, 1) ON DUPLICATE KEY UPDATE updated_at = IF(request_count < :updated_limit, CURRENT_TIMESTAMP, updated_at), request_count = IF(request_count < :count_limit, request_count + 1, request_count)');
        $statement->execute([
            'user_id' => $userId,
            'usage_date' => $usageDate,
            'updated_limit' => $dailyLimit,
            'count_limit' => $dailyLimit,
        ]);
        return $statement->rowCount() > 0;
    }

    $statement = $pdo->prepare('INSERT INTO ai_usage (user_id, usage_date, request_count) VALUES (:user_id, :usage_date, 1) ON CONFLICT(user_id, usage_date) DO UPDATE SET request_count = request_count + 1, updated_at = CURRENT_TIMESTAMP WHERE request_count < :daily_limit');
    $statement->execute(['user_id' => $userId, 'usage_date' => $usageDate, 'daily_limit' => $dailyLimit]);

    return $statement->rowCount() === 1;
}

function getCustomer(PDO $pdo, int $id, int $businessId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND business_id = :business_id');
    $stmt->execute(['id' => $id, 'business_id' => $businessId]);

    return $stmt->fetch() ?: null;
}

function getProduct(PDO $pdo, int $id, int $businessId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id AND business_id = :business_id');
    $stmt->execute(['id' => $id, 'business_id' => $businessId]);

    return $stmt->fetch() ?: null;
}

function getInvoice(PDO $pdo, int $invoiceId, int $businessId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = :id AND business_id = :business_id');
    $stmt->execute(['id' => $invoiceId, 'business_id' => $businessId]);

    return $stmt->fetch() ?: null;
}

function transitionQuotationStatus(
    PDO $pdo,
    int $quotationId,
    int $businessId,
    string $action,
    ?string $today = null,
    bool $publicActor = false
): string
{
    $actions = ['send' => ['Draft', 'Sent'], 'accept' => ['Sent', 'Accepted'], 'reject' => ['Sent', 'Rejected'], 'expire' => ['Sent', 'Expired']];
    if (!isset($actions[$action])) {
        throw new InvalidArgumentException('Choose a supported quotation action.');
    }

    $quoteStmt = $pdo->prepare('SELECT * FROM quotations WHERE id = :id AND business_id = :business_id');
    $quoteStmt->execute(['id' => $quotationId, 'business_id' => $businessId]);
    $quote = $quoteStmt->fetch();
    if (!$quote) {
        throw new InvalidArgumentException('This quotation is no longer available.');
    }
    $today ??= gmdate('Y-m-d');
    if ($quote['status'] === 'Sent' && $quote['expiry_date'] < $today) {
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("UPDATE quotations SET status = 'Expired' WHERE id = :id AND business_id = :business_id AND status = 'Sent'");
            $update->execute(['id' => $quotationId, 'business_id' => $businessId]);
            if ($update->rowCount() === 1) {
                revokeCustomerPortalToken($pdo, 'quotation', $quotationId, $businessId);
                $pdo->prepare("INSERT INTO quotation_events (quotation_id, user_id, event_type) VALUES (:quotation_id, :user_id, 'expired')")
                    ->execute(['quotation_id' => $quotationId, 'user_id' => $publicActor ? null : ($_SESSION['user_id'] ?? null)]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        if ($action === 'expire') {
            return 'Expired';
        }
        throw new InvalidArgumentException('This quotation has expired.');
    }
    if ($action === 'expire') {
        throw new InvalidArgumentException('Only an expired sent quotation can be marked expired.');
    }
    [$expectedStatus, $nextStatus] = $actions[$action];
    if ($quote['status'] !== $expectedStatus) {
        throw new InvalidArgumentException('That quotation action is not available in its current status.');
    }
    if ($action === 'send' && $quote['expiry_date'] < $today) {
        throw new InvalidArgumentException('An expired quotation cannot be marked as sent.');
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE quotations SET status = :status WHERE id = :id AND business_id = :business_id AND status = :expected_status');
        $update->execute([
            'status' => $nextStatus,
            'id' => $quotationId,
            'business_id' => $businessId,
            'expected_status' => $expectedStatus,
        ]);
        if ($update->rowCount() !== 1) {
            throw new InvalidArgumentException('The quotation changed before this action completed.');
        }
        if (in_array($nextStatus, ['Rejected', 'Expired'], true)) {
            revokeCustomerPortalToken($pdo, 'quotation', $quotationId, $businessId);
        }
        $event = $pdo->prepare('INSERT INTO quotation_events (quotation_id, user_id, event_type) VALUES (:quotation_id, :user_id, :event_type)');
        $event->execute([
            'quotation_id' => $quotationId,
            'user_id' => $publicActor ? null : ($_SESSION['user_id'] ?? null),
            'event_type' => strtolower($nextStatus),
        ]);
        $pdo->commit();
        return $nextStatus;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function convertAcceptedQuotationToInvoice(PDO $pdo, int $quotationId, int $businessId, ?string $today = null): int
{
    $today ??= gmdate('Y-m-d');
    $pdo->beginTransaction();
    try {
        $quoteStmt = $pdo->prepare('SELECT * FROM quotations WHERE id = :id AND business_id = :business_id');
        $quoteStmt->execute(['id' => $quotationId, 'business_id' => $businessId]);
        $quote = $quoteStmt->fetch();
        if (!$quote || $quote['status'] !== 'Accepted' || $quote['converted_invoice_id'] !== null) {
            throw new InvalidArgumentException('Only an accepted, unconverted quotation can become an invoice.');
        }
        if (!getCustomer($pdo, (int) $quote['customer_id'], $businessId)) {
            throw new InvalidArgumentException('The quotation customer is no longer available.');
        }

        $numberCheck = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE business_id = :business_id AND LOWER(invoice_number) = LOWER(:invoice_number)');
        $invoiceNumber = '';
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $invoiceNumber = 'INV-' . str_replace('-', '', $today) . '-' . random_int(100, 999);
            $numberCheck->execute(['business_id' => $businessId, 'invoice_number' => $invoiceNumber]);
            if ((int) $numberCheck->fetchColumn() === 0) {
                break;
            }
            $invoiceNumber = '';
        }
        if ($invoiceNumber === '') {
            throw new RuntimeException('Unable to allocate a unique invoice number from the accepted quotation.');
        }

        $insert = $pdo->prepare("INSERT INTO invoices (business_id, customer_id, invoice_number, issue_date, due_date, currency, status, document_label, document_title, document_kind, subtotal, discount, tax, total, notes, terms) VALUES (:business_id, :customer_id, :invoice_number, :issue_date, :due_date, :currency, 'Draft', 'PAYMENT REQUEST', 'Invoice', 'invoice', :subtotal, :discount, :tax, :total, :notes, :terms)");
        $insert->execute([
            'business_id' => $businessId,
            'customer_id' => (int) $quote['customer_id'],
            'invoice_number' => $invoiceNumber,
            'issue_date' => $today,
            'due_date' => gmdate('Y-m-d', strtotime($today . ' +14 days')),
            'currency' => $quote['currency'],
            'subtotal' => $quote['subtotal'],
            'discount' => $quote['discount'],
            'tax' => $quote['tax'],
            'total' => $quote['total'],
            'notes' => $quote['notes'],
            'terms' => $quote['terms'],
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $copyItems = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, discount, tax_rate, line_total) SELECT :invoice_id, description, quantity, unit_price, discount, tax_rate, line_total FROM quotation_items WHERE quotation_id = :quotation_id');
        $copyItems->execute(['invoice_id' => $invoiceId, 'quotation_id' => $quotationId]);
        recordInvoiceEvent($pdo, $invoiceId, 'converted_from_quotation', null, 'Draft', [
            'quotation_id' => $quotationId,
            'quotation_number' => (string) $quote['quotation_number'],
        ]);

        $update = $pdo->prepare("UPDATE quotations SET status = 'Converted', converted_invoice_id = :invoice_id WHERE id = :quotation_id AND business_id = :business_id AND status = 'Accepted' AND converted_invoice_id IS NULL");
        $update->execute(['invoice_id' => $invoiceId, 'quotation_id' => $quotationId, 'business_id' => $businessId]);
        if ($update->rowCount() !== 1) {
            throw new InvalidArgumentException('This quotation was already converted.');
        }
        revokeCustomerPortalToken($pdo, 'quotation', $quotationId, $businessId);
        $event = $pdo->prepare("INSERT INTO quotation_events (quotation_id, user_id, event_type, details_json) VALUES (:quotation_id, :user_id, 'converted', :details_json)");
        $event->execute([
            'quotation_id' => $quotationId,
            'user_id' => $_SESSION['user_id'] ?? null,
            'details_json' => json_encode(['invoice_id' => $invoiceId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        $pdo->commit();
        return $invoiceId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function invoicePaymentSummaryFromCents(array $invoice, int $recordedPaidCents, ?string $today = null): array
{
    $totalCents = max(0, (int) round((float) $invoice['total'] * 100));
    $paidCents = max(0, $recordedPaidCents);
    if ($paidCents === 0 && ($invoice['status'] ?? '') === 'Paid') {
        $paidCents = $totalCents;
    }

    $balanceCents = max(0, $totalCents - $paidCents);
    $status = (string) ($invoice['status'] ?? 'Draft');
    if ($status !== 'Draft' && $status !== 'Cancelled') {
        if (($status === 'Paid' && $recordedPaidCents === 0) || ($totalCents > 0 && $balanceCents === 0)) {
            $status = 'Paid';
        } elseif (!empty($invoice['due_date']) && $invoice['due_date'] < ($today ?? gmdate('Y-m-d'))) {
            $status = 'Overdue';
        } elseif ($paidCents > 0) {
            $status = 'Partially Paid';
        } else {
            $status = 'Issued';
        }
    }

    return [
        'amount_paid_cents' => min($paidCents, $totalCents),
        'balance_cents' => $balanceCents,
        'status' => $status,
    ];
}

function getInvoicePaymentSummary(PDO $pdo, array $invoice): array
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = :invoice_id');
    $stmt->execute(['invoice_id' => (int) $invoice['id']]);
    $recordedPaidCents = (int) round((float) $stmt->fetchColumn() * 100);

    return invoicePaymentSummaryFromCents($invoice, $recordedPaidCents);
}

function recordInvoiceEvent(
    PDO $pdo,
    int $invoiceId,
    string $eventType,
    ?string $fromStatus = null,
    ?string $toStatus = null,
    array $details = []
): void {
    $stmt = $pdo->prepare('INSERT INTO invoice_events (invoice_id, user_id, event_type, from_status, to_status, details_json) VALUES (:invoice_id, :user_id, :event_type, :from_status, :to_status, :details_json)');
    $stmt->execute([
        'invoice_id' => $invoiceId,
        'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'event_type' => $eventType,
        'from_status' => $fromStatus,
        'to_status' => $toStatus,
        'details_json' => $details ? json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function transitionInvoiceStatus(PDO $pdo, int $invoiceId, int $businessId, string $action): array
{
    if (!in_array($action, ['issue_invoice', 'cancel_invoice'], true)) {
        throw new InvalidArgumentException('Choose a supported invoice action.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $invoice = getInvoice($pdo, $invoiceId, $businessId);
        if (!$invoice) {
            throw new InvalidArgumentException('This invoice is no longer available.');
        }

        if ($action === 'issue_invoice') {
            if ($invoice['status'] !== 'Draft') {
                throw new InvalidArgumentException('Only a draft invoice can be issued.');
            }
            $update = $pdo->prepare("UPDATE invoices SET status = 'Issued' WHERE id = :invoice_id AND business_id = :business_id AND status = 'Draft'");
            $nextStatus = 'Issued';
        } else {
            if (!in_array($invoice['status'], ['Draft', 'Issued'], true)) {
                throw new InvalidArgumentException('Only draft or issued invoices can be cancelled.');
            }
            $paymentCheck = $pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = :invoice_id');
            $paymentCheck->execute(['invoice_id' => $invoiceId]);
            if ((int) $paymentCheck->fetchColumn() > 0) {
                throw new InvalidArgumentException('An invoice with recorded payments cannot be cancelled. Resolve its balance first.');
            }
            $update = $pdo->prepare("UPDATE invoices SET status = 'Cancelled' WHERE id = :invoice_id AND business_id = :business_id AND status IN ('Draft', 'Issued')");
            $nextStatus = 'Cancelled';
        }
        $update->execute(['invoice_id' => $invoiceId, 'business_id' => $businessId]);
        if ($update->rowCount() !== 1) {
            throw new InvalidArgumentException('The invoice status changed before this action completed. Reload and try again.');
        }
        if ($nextStatus === 'Cancelled') {
            revokeCustomerPortalToken($pdo, 'invoice', $invoiceId, $businessId);
        }

        recordInvoiceEvent($pdo, $invoiceId, $action, (string) $invoice['status'], $nextStatus);
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return ['from_status' => (string) $invoice['status'], 'to_status' => $nextStatus];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
