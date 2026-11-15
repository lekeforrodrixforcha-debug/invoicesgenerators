<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    error_log('Invoice PDF download requested, but Composer dependencies are not installed.');
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('PDF generation is unavailable. Install the application dependencies and try again.');
}
require_once $autoloadPath;
require_once __DIR__ . '/app/pdf.php';

$business = getCurrentBusiness($db);
$invoiceId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$business || $invoiceId === false || $invoiceId < 1) {
    http_response_code(404);
    exit('Invoice not found.');
}

$invoice = getInvoice($db, (int) $invoiceId, (int) $business['id']);
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}

try {
    $itemStmt = $db->prepare('SELECT description, quantity, unit_price, tax_rate, line_total FROM invoice_items WHERE invoice_id = :invoice_id ORDER BY id ASC');
    $itemStmt->execute(['invoice_id' => (int) $invoice['id']]);
    $items = $itemStmt->fetchAll();

    $customer = null;
    if ($invoice['customer_id'] !== null) {
        $customerStmt = $db->prepare('SELECT name, company, address, email, phone FROM customers WHERE id = :customer_id AND business_id = :business_id');
        $customerStmt->execute(['customer_id' => (int) $invoice['customer_id'], 'business_id' => (int) $business['id']]);
        $customer = $customerStmt->fetch() ?: null;
    }

    $paymentSummary = getInvoicePaymentSummary($db, $invoice);
    $html = renderInvoicePdfHtml($invoice, $items, $customer, $business, $paymentSummary);
    $options = new Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->setChroot(__DIR__);

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdf = $dompdf->output();
    $safeInvoiceNumber = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice['invoice_number']);
    $filename = 'invoice-' . trim((string) $safeInvoiceNumber, '-.') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    echo $pdf;
} catch (Throwable $exception) {
    error_log('Invoice PDF generation failed for invoice ' . (int) $invoice['id'] . ': ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'The invoice PDF could not be generated. Please try again or contact support.';
}
