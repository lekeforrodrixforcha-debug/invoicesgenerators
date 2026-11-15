<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/pdf.php';

$token = (string) ($_GET['token'] ?? '');
if (!isValidCustomerPortalToken($token)) {
    http_response_code(404);
    exit('This customer link is invalid or has expired.');
}
$tokenHash = hash('sha256', $token);
$invoiceToken = $db->prepare("SELECT i.*, t.token_hash, b.name AS business_name, b.email AS business_email, b.phone AS business_phone, b.address AS business_address, b.city AS business_city, b.country AS business_country, b.tax_id AS business_tax_id, b.logo_path AS business_logo, c.name AS customer_name, c.company AS customer_company, c.email AS customer_email, c.address AS customer_address FROM customer_portal_tokens t JOIN invoices i ON i.id = t.invoice_id JOIN businesses b ON b.id = i.business_id LEFT JOIN customers c ON c.id = i.customer_id WHERE t.token_hash = :token_hash AND t.revoked_at IS NULL AND i.status NOT IN ('Draft', 'Cancelled')");
$invoiceToken->execute(['token_hash' => $tokenHash]);
$invoice = $invoiceToken->fetch() ?: null;
$quotation = null;
if (!$invoice) {
    $quoteToken = $db->prepare("SELECT q.*, t.token_hash, b.name AS business_name, b.email AS business_email, b.phone AS business_phone, b.address AS business_address, c.name AS customer_name, c.company AS customer_company, c.email AS customer_email FROM quotation_portal_tokens t JOIN quotations q ON q.id = t.quotation_id JOIN businesses b ON b.id = q.business_id LEFT JOIN customers c ON c.id = q.customer_id WHERE t.token_hash = :token_hash AND t.revoked_at IS NULL AND q.status NOT IN ('Draft', 'Rejected', 'Expired', 'Converted')");
    $quoteToken->execute(['token_hash' => $tokenHash]);
    $quotation = $quoteToken->fetch() ?: null;
}
if (!$invoice && !$quotation) {
    http_response_code(404);
    exit('This customer link is invalid or has expired.');
}

$errorMessage = null;
if ($quotation && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['accept', 'reject'], true)) {
            throw new InvalidArgumentException('Choose accept or reject.');
        }
        transitionQuotationStatus($db, (int) $quotation['id'], (int) $quotation['business_id'], $action, null, true);
        redirect(customerPortalPath($token));
    } catch (Throwable $exception) {
        if ($exception instanceof InvalidArgumentException) {
            $errorMessage = $exception->getMessage();
        } else {
            error_log('Customer quotation response failed: ' . $exception->getMessage());
            $errorMessage = 'Your response could not be saved. Please try again.';
        }
    }
}

if ($quotation && $quotation['status'] === 'Sent' && $quotation['expiry_date'] < gmdate('Y-m-d')) {
    try {
        $quotation['status'] = transitionQuotationStatus($db, (int) $quotation['id'], (int) $quotation['business_id'], 'expire', null, true);
    } catch (Throwable $exception) {
        error_log('Customer quotation expiry update failed: ' . $exception->getMessage());
    }
}

header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$document = $invoice ?: $quotation;
$isQuotation = $quotation !== null;
$businessId = (int) $document['business_id'];
$businessStmt = $db->prepare('SELECT * FROM businesses WHERE id = :id');
$businessStmt->execute(['id' => $businessId]);
$business = $businessStmt->fetch();
if (!$business) {
    http_response_code(404);
    exit('This customer link is invalid or has expired.');
}
$customer = $document['customer_id'] === null ? null : getCustomer($db, (int) $document['customer_id'], $businessId);

if ($invoice) {
    $itemsStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id');
    $itemsStmt->execute(['id' => (int) $invoice['id']]);
    $items = $itemsStmt->fetchAll();
    $summary = getInvoicePaymentSummary($db, $invoice);
    $pdfUrl = customerPortalPath($token, true);
    $documentForRender = $invoice;
} else {
    $itemsStmt = $db->prepare('SELECT * FROM quotation_items WHERE quotation_id = :id ORDER BY id');
    $itemsStmt->execute(['id' => (int) $quotation['id']]);
    $items = $itemsStmt->fetchAll();
    $pdfUrl = customerPortalPath($token, true);
    $quotation['invoice_number'] = $quotation['quotation_number'];
    $quotation['due_date'] = $quotation['expiry_date'];
    $quotation['document_label'] = 'QUOTATION · VALID UNTIL ' . $quotation['expiry_date'];
    $quotation['document_title'] = 'Quotation';
    $documentForRender = $quotation;
    $summary = [
        'status' => (string) $quotation['status'],
        'amount_paid_cents' => 0,
        'balance_cents' => (int) round((float) $quotation['total'] * 100),
    ];
}
$actionsMarkup = '<nav class="portal-actions" aria-label="Document actions">'
    . '<a class="btn" href="' . e($pdfUrl) . '">Download PDF</a>';
if ($errorMessage !== null) {
    $actionsMarkup .= '<p class="portal-error" role="alert">' . e($errorMessage) . '</p>';
}
if (!$isQuotation && !in_array($summary['status'], ['Paid', 'Cancelled'], true)) {
    $actionsMarkup .= '<p class="portal-note">To arrange payment, contact ' . e((string) $business['name'])
        . (empty($business['email']) ? '' : ' at ' . e((string) $business['email'])) . '.</p>';
}
if ($isQuotation && $quotation['status'] === 'Sent' && $quotation['expiry_date'] >= gmdate('Y-m-d')) {
    $actionsMarkup .= '<form method="post"><input type="hidden" name="csrf" value="' . e(csrfToken()) . '">'
        . '<button class="btn" type="submit" name="action" value="accept">Accept quotation</button>'
        . '<button class="btn btn-secondary" type="submit" name="action" value="reject">Decline quotation</button></form>';
}
$actionsMarkup .= '</nav>';
$documentHtml = renderInvoicePdfHtml($documentForRender, $items, $customer, $business, $summary, true);
echo str_replace('<body>', '<body>' . $actionsMarkup, $documentHtml);
