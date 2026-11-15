<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
$expenseId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$business || $expenseId === false || $expenseId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}

$statement = $db->prepare('SELECT receipt_path FROM expenses WHERE id = :id AND business_id = :business_id');
$statement->execute(['id' => (int) $expenseId, 'business_id' => (int) $business['id']]);
$relativePath = $statement->fetchColumn();
if (!is_string($relativePath) || !preg_match('~^storage/expense-receipts/' . (int) $business['id'] . '/receipt-[a-f0-9]{32}\.(pdf|jpg|png|webp)$~', $relativePath, $matches)) {
    http_response_code(404);
    exit('Receipt not found.');
}

$baseDirectory = realpath(__DIR__ . '/storage/expense-receipts/' . (int) $business['id']);
$absolutePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
if ($baseDirectory === false || $absolutePath === false || !str_starts_with($absolutePath, $baseDirectory . DIRECTORY_SEPARATOR) || !is_file($absolutePath)) {
    http_response_code(404);
    exit('Receipt not found.');
}

$mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
if ($mimeType !== $mimeTypes[$matches[1]]) {
    http_response_code(404);
    exit('Receipt not found.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: attachment; filename="expense-receipt-' . (int) $expenseId . '.' . $matches[1] . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($absolutePath);
