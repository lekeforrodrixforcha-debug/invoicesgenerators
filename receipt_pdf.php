<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    error_log('Payment receipt requested, but Composer dependencies are not installed.');
    http_response_code(503);
    exit('PDF generation is unavailable. Please contact support.');
}
require_once $autoloadPath;
require_once __DIR__ . '/app/pdf.php';

$business = getCurrentBusiness($db);
$paymentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$business || $paymentId === false || $paymentId < 1) {
    http_response_code(404);
    exit('Payment receipt not found.');
}

$stmt = $db->prepare('SELECT p.*, i.invoice_number, i.currency, i.customer_id, i.business_id, c.name AS customer_name, c.company AS customer_company, b.name AS business_name, b.email AS business_email, b.phone AS business_phone, b.address AS business_address, b.city AS business_city, b.country AS business_country, b.tax_id AS business_tax_id, b.logo_path AS business_logo FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id JOIN businesses b ON b.id = i.business_id LEFT JOIN customers c ON c.id = i.customer_id WHERE p.id = :payment_id AND b.id = :business_id');
$stmt->execute(['payment_id' => (int) $paymentId, 'business_id' => (int) $business['id']]);
$payment = $stmt->fetch();
if (!$payment) {
    http_response_code(404);
    exit('Payment receipt not found.');
}

try {
    $logo = resolveBusinessLogoPath($payment['business_logo'] ?? null, (int) $business['id']);
    $logoMarkup = '';
    if ($logo !== null) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($logo);
        if (in_array($mime, ['image/png', 'image/jpeg'], true)) {
            $content = file_get_contents($logo);
            if ($content === false) {
                throw new RuntimeException('Unable to read the business logo.');
            }
            $logoMarkup = '<img src="data:' . e($mime) . ';base64,' . base64_encode($content) . '" alt="" style="max-width:90px;max-height:60px">';
        }
    }
    $html = renderPaymentReceiptPdfHtml($payment, $logoMarkup);
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
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="receipt-' . (int) $payment['id'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    echo $pdf;
} catch (Throwable $exception) {
    error_log('Payment receipt generation failed for payment ' . (int) $payment['id'] . ': ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'The payment receipt could not be generated. Please try again.';
}
