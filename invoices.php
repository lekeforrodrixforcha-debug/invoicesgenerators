<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}

$stmt = $db->prepare('SELECT i.*, c.name AS customer_name, COALESCE(p.amount_paid, 0) AS recorded_paid FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) AS amount_paid FROM invoice_payments GROUP BY invoice_id) p ON p.invoice_id = i.id WHERE i.business_id = :business_id ORDER BY i.id DESC');
$stmt->execute(['business_id' => $business['id']]);
$invoices = $stmt->fetchAll();
foreach ($invoices as &$invoice) {
    $invoice['payment_summary'] = invoicePaymentSummaryFromCents(
        $invoice,
        (int) round((float) $invoice['recorded_paid'] * 100)
    );
}
unset($invoice);

$customersStmt = $db->prepare('SELECT id, name FROM customers WHERE business_id = :business_id ORDER BY LOWER(name)');
$customersStmt->execute(['business_id' => $business['id']]);
$customers = $customersStmt->fetchAll();

$filters = [
    'q' => trim(substr((string) ($_GET['q'] ?? ''), 0, 100)),
    'status' => (string) ($_GET['status'] ?? ''),
    'customer_id' => (string) ($_GET['customer_id'] ?? ''),
    'currency' => (string) ($_GET['currency'] ?? ''),
    'date_from' => (string) ($_GET['date_from'] ?? ''),
    'date_to' => (string) ($_GET['date_to'] ?? ''),
];
$filterError = null;
$allowedStatuses = ['Draft', 'Issued', 'Partially Paid', 'Overdue', 'Paid', 'Cancelled'];
$allowedCurrencies = ['USD', 'EUR', 'GHS', 'GBP', 'XAF'];
if ($filters['status'] !== '' && !in_array($filters['status'], $allowedStatuses, true)) {
    $filters['status'] = '';
}
if ($filters['currency'] !== '' && !in_array($filters['currency'], $allowedCurrencies, true)) {
    $filters['currency'] = '';
}
if ($filters['customer_id'] !== '' && !ctype_digit($filters['customer_id'])) {
    $filters['customer_id'] = '';
}
if (($filters['date_from'] !== '' && !isValidInvoiceDate($filters['date_from']))
    || ($filters['date_to'] !== '' && !isValidInvoiceDate($filters['date_to']))) {
    $filterError = 'Enter valid dates to filter invoices.';
} elseif ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
    $filterError = 'The start date must be on or before the end date.';
}

$invoices = array_values(array_filter($invoices, static function (array $invoice) use ($filters, $filterError): bool {
    if ($filterError !== null) {
        return false;
    }
    if ($filters['q'] !== '' && stripos($invoice['invoice_number'], $filters['q']) === false && stripos((string) ($invoice['customer_name'] ?? ''), $filters['q']) === false) {
        return false;
    }
    if ($filters['status'] !== '' && $invoice['payment_summary']['status'] !== $filters['status']) {
        return false;
    }
    if ($filters['customer_id'] !== '' && (string) ($invoice['customer_id'] ?? '') !== $filters['customer_id']) {
        return false;
    }
    if ($filters['currency'] !== '' && $invoice['currency'] !== $filters['currency']) {
        return false;
    }
    if ($filters['date_from'] !== '' && $invoice['issue_date'] < $filters['date_from']) {
        return false;
    }
    if ($filters['date_to'] !== '' && $invoice['issue_date'] > $filters['date_to']) {
        return false;
    }

    return true;
}));

$pageTitle = 'Invoices';
$siteTitle = 'Invoices | Invoices Generators';
$activeNav = 'invoices.php';
include __DIR__ . '/includes/header.php';
?>

<div class="app-page">
    <section class="card">
        <div class="card-head">
            <h3>Invoice history</h3>
            <a href="templates.php" class="btn btn-primary">Create invoice</a>
        </div>

        <?php if ($filterError !== null): ?>
            <div class="notice notice-error"><?= e($filterError); ?></div>
        <?php endif; ?>
        <form method="get" action="invoices.php" class="invoice-filter-form">
            <div class="form-grid">
                <div class="form-group">
                    <label for="invoice-search">Search invoice or client</label>
                    <input id="invoice-search" type="search" name="q" value="<?= e($filters['q']); ?>" maxlength="100">
                </div>
                <div class="form-group">
                    <label for="invoice-filter-status">Status</label>
                    <select id="invoice-filter-status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach ($allowedStatuses as $statusOption): ?>
                            <option value="<?= e($statusOption); ?>" <?= $filters['status'] === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="invoice-filter-customer">Customer</label>
                    <select id="invoice-filter-customer" name="customer_id">
                        <option value="">All customers</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int) $customer['id']; ?>" <?= $filters['customer_id'] === (string) $customer['id'] ? 'selected' : ''; ?>><?= e($customer['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="invoice-filter-currency">Currency</label>
                    <select id="invoice-filter-currency" name="currency">
                        <option value="">All currencies</option>
                        <?php foreach ($allowedCurrencies as $currencyOption): ?>
                            <option value="<?= e($currencyOption); ?>" <?= $filters['currency'] === $currencyOption ? 'selected' : ''; ?>><?= e($currencyOption); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="invoice-date-from">Issue date from</label>
                    <input id="invoice-date-from" type="date" name="date_from" value="<?= e($filters['date_from']); ?>">
                </div>
                <div class="form-group">
                    <label for="invoice-date-to">Issue date to</label>
                    <input id="invoice-date-to" type="date" name="date_to" value="<?= e($filters['date_to']); ?>">
                </div>
            </div>
            <div class="inline-actions">
                <button class="btn btn-primary" type="submit">Apply filters</button>
                <a class="btn btn-secondary" href="invoices.php">Clear</a>
            </div>
        </form>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><a href="invoice.php?id=<?= (int) $invoice['id']; ?>"><?= e($invoice['invoice_number']); ?></a></td>
                            <td><?= e($invoice['customer_name'] ?: 'Direct customer'); ?></td>
                            <td><?= e($invoice['issue_date']); ?></td>
                            <td><span class="status-label status-<?= e(strtolower(str_replace(' ', '-', $invoice['payment_summary']['status']))); ?>"><?= e($invoice['payment_summary']['status']); ?></span></td>
                            <td>
                                <?= e(formatMoney((float) $invoice['total'], $invoice['currency'] ?? 'USD')); ?>
                                <small class="invoice-balance-hint">Balance <?= e(formatMoney($invoice['payment_summary']['balance_cents'] / 100, $invoice['currency'] ?? 'USD')); ?></small>
                            </td>
                            <td>
                                <a class="invoice-view-action" href="invoice.php?id=<?= (int) $invoice['id']; ?>" aria-label="View invoice <?= e($invoice['invoice_number']); ?>">
                                    <span>View</span>
                                    <svg viewBox="0 0 16 16" aria-hidden="true">
                                        <path d="M3.25 8h9.5M8.5 3.75 12.75 8 8.5 12.25"></path>
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$invoices): ?>
                        <tr><td colspan="6">No invoices match these filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
