<?php
require_once __DIR__ . '/app/init.php';
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    http_response_code(503);
    exit('PDF generation is unavailable.');
}
require_once $autoloadPath;
require_once __DIR__ . '/app/pdf.php';

$token = (string) ($_GET['token'] ?? '');
if (!isValidCustomerPortalToken($token)) {
    http_response_code(404);
    exit('Document not found.');
}
$hash = hash('sha256', $token);
$invoiceToken = $db->prepare("SELECT i.*, i.business_id AS document_business_id FROM customer_portal_tokens t JOIN invoices i ON i.id = t.invoice_id WHERE t.token_hash = :hash AND t.revoked_at IS NULL AND i.status NOT IN ('Draft', 'Cancelled')");
$invoiceToken->execute(['hash' => $hash]);
$invoice = $invoiceToken->fetch() ?: null;
$documentType = 'invoice';
$document = $invoice;
if (!$document) {
    $quoteToken = $db->prepare("SELECT q.*, q.business_id AS quotation_business_id FROM quotation_portal_tokens t JOIN quotations q ON q.id = t.quotation_id WHERE t.token_hash = :hash AND t.revoked_at IS NULL AND q.status NOT IN ('Draft', 'Rejected', 'Expired', 'Converted')");
    $quoteToken->execute(['hash' => $hash]);
    $document = $quoteToken->fetch() ?: null;
    $documentType = 'quotation';
}
if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

try {
    $businessId = $documentType === 'invoice' ? (int) $document['document_business_id'] : (int) $document['quotation_business_id'];
    $businessStmt = $db->prepare('SELECT * FROM businesses WHERE id = :id');
    $businessStmt->execute(['id' => $businessId]);
    $business = $businessStmt->fetch();
    if ($documentType === 'invoice') {
        $itemStmt = $db->prepare('SELECT description, quantity, unit_price, tax_rate, line_total FROM invoice_items WHERE invoice_id = :id ORDER BY id');
        $itemStmt->execute(['id' => (int) $document['id']]);
        $customer = getCustomer($db, (int) $document['customer_id'], $businessId);
        $summary = getInvoicePaymentSummary($db, $document);
    } else {
        $itemStmt = $db->prepare('SELECT description, quantity, unit_price, tax_rate, line_total FROM quotation_items WHERE quotation_id = :id ORDER BY id');
        $itemStmt->execute(['id' => (int) $document['id']]);
        $customer = getCustomer($db, (int) $document['customer_id'], $businessId);
        $document['invoice_number'] = $document['quotation_number'];
        $document['due_date'] = $document['expiry_date'];
        $document['document_label'] = 'QUOTATION · VALID UNTIL ' . $document['expiry_date'];
        $document['document_title'] = 'Quotation';
        $summary = ['status' => (string) $document['status'], 'amount_paid_cents' => 0, 'balance_cents' => (int) round((float) $document['total'] * 100)];
    }
    $html = renderInvoicePdfHtml($document, $itemStmt->fetchAll(), $customer, $business, $summary);
    $options = new Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->setChroot(__DIR__);
    $renderer = new Dompdf\Dompdf($options);
    $renderer->loadHtml($html, 'UTF-8');
    $renderer->setPaper('A4', 'portrait');
    $renderer->render();
    $pdf = $renderer->output();
    $number = $documentType === 'invoice' ? $document['invoice_number'] : $document['quotation_number'];
    $prefix = $documentType === 'invoice' ? 'invoice-' : 'quotation-';
    $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $number);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $prefix . trim((string) $safeNumber, '-.') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
} catch (Throwable $exception) {
    error_log('Public document PDF generation failed: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'The document PDF could not be generated. Please try again.';
}
