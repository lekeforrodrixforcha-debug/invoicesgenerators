<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    http_response_code(404);
    exit;
}

$logoPath = null;
if (isset($_GET['invoice'])) {
    $invoiceId = filter_var($_GET['invoice'], FILTER_VALIDATE_INT);
    if ($invoiceId === false || $invoiceId < 1) {
        http_response_code(404);
        exit;
    }

    $stmt = $db->prepare('SELECT logo_path FROM invoices WHERE id = :id AND business_id = :business_id LIMIT 1');
    $stmt->execute([
        'id' => $invoiceId,
        'business_id' => $business['id'],
    ]);
    $logoPath = $stmt->fetchColumn();
}

if (!is_string($logoPath) || $logoPath === '') {
    $logoPath = $business['logo_path'] ?? null;
}

$logoAbsolutePath = resolveBusinessLogoPath(is_string($logoPath) ? $logoPath : null, (int) $business['id']);
if ($logoAbsolutePath === null) {
    http_response_code(404);
    exit;
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($logoAbsolutePath);
$allowedMimeTypes = ['image/png', 'image/jpeg', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($logoAbsolutePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($logoAbsolutePath);
