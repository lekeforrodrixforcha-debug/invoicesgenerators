<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    error_log('Quotation PDF requested, but Composer dependencies are not installed.');
    http_response_code(503);
    exit('PDF generation is unavailable. Please contact support.');
}
require_once $autoloadPath;
require_once __DIR__ . '/app/pdf.php';

$business = getCurrentBusiness($db);
$quoteId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$business || $quoteId === false || $quoteId < 1) {
    http_response_code(404);
    exit('Quotation not found.');
}
$quoteStmt = $db->prepare('SELECT * FROM quotations WHERE id = :id AND business_id = :business_id');
$quoteStmt->execute(['id' => (int) $quoteId, 'business_id' => (int) $business['id']]);
$quote = $quoteStmt->fetch();
if (!$quote) {
    http_response_code(404);
    exit('Quotation not found.');
}

try {
    $itemStmt = $db->prepare('SELECT description, quantity, unit_price, tax_rate, line_total FROM quotation_items WHERE quotation_id = :quotation_id ORDER BY id');
    $itemStmt->execute(['quotation_id' => (int) $quote['id']]);
    $customer = getCustomer($db, (int) $quote['customer_id'], (int) $business['id']);
    $document = array_merge($quote, [
        'invoice_number' => $quote['quotation_number'],
        'due_date' => $quote['expiry_date'],
        'status' => $quote['status'],
        'document_label' => 'QUOTATION · VALID UNTIL ' . $quote['expiry_date'],
        'document_title' => 'Quotation',
    ]);
    $summary = [
        'status' => (string) $quote['status'],
        'amount_paid_cents' => 0,
        'balance_cents' => (int) round((float) $quote['total'] * 100),
    ];
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
    $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $quote['quotation_number']);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="quotation-' . trim((string) $safeNumber, '-.') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    echo $pdf;
} catch (Throwable $exception) {
    error_log('Quotation PDF generation failed for quotation ' . (int) $quote['id'] . ': ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'The quotation PDF could not be generated. Please try again.';
}
