<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$business = getCurrentBusiness($db);
$type = (string) ($_POST['document_type'] ?? '');
$documentId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
$action = (string) ($_POST['action'] ?? '');
if (!$business || !in_array($type, ['invoice', 'quotation'], true) || $documentId === false || $documentId < 1
    || !in_array($action, ['create', 'revoke'], true)) {
    http_response_code(400);
    exit('Invalid portal link request.');
}

try {
    verifyCsrf();
    if ($action === 'create') {
        if ($type === 'invoice') {
            $invoice = getInvoice($db, (int) $documentId, (int) $business['id']);
            if (!$invoice) {
                throw new InvalidArgumentException('This invoice is no longer available.');
            }
            if (($invoice['document_kind'] ?? 'invoice') === 'proforma') {
                throw new InvalidArgumentException('Customer links cannot be created for existing pro forma documents.');
            }
        }
        $token = createCustomerPortalToken($db, $type, (int) $documentId, (int) $business['id']);
        setFlash('portal_link', customerPortalPath($token));
    } else {
        revokeCustomerPortalToken($db, $type, (int) $documentId, (int) $business['id']);
        setFlash('success', 'The customer portal link has been revoked.');
    }
    redirect($type === 'invoice' ? 'invoice.php?id=' . (int) $documentId : 'quotes.php');
} catch (Throwable $exception) {
    if ($exception instanceof InvalidArgumentException) {
        setFlash('error', $exception->getMessage());
    } else {
        error_log('Customer portal link update failed: ' . $exception->getMessage());
        setFlash('error', 'The customer portal link could not be updated. Please try again.');
    }
    redirect($type === 'invoice' ? 'invoice.php?id=' . (int) $documentId : 'quotes.php');
}
