<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);
$dashboardUser = getCurrentUser($db);
if (($dashboardUser['role'] ?? 'user') === 'admin') {
    redirect('admin.php');
}

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}

$stmt = $db->prepare('SELECT i.*, c.name AS customer_name, COALESCE(p.amount_paid, 0) AS recorded_paid FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN (SELECT invoice_id, SUM(amount) AS amount_paid FROM invoice_payments GROUP BY invoice_id) p ON p.invoice_id = i.id WHERE i.business_id = :business_id ORDER BY i.id DESC');
$stmt->execute(['business_id' => $business['id']]);
$allInvoices = $stmt->fetchAll();
$totalInvoices = count($allInvoices);

$stmt = $db->prepare('SELECT COUNT(*) FROM customers WHERE business_id = :business_id');
$stmt->execute(['business_id' => $business['id']]);
$totalCustomers = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE business_id = :business_id');
$stmt->execute(['business_id' => $business['id']]);
$totalProducts = (int) $stmt->fetchColumn();

$paidByCurrency = [];
$outstandingByCurrency = [];
$invoicedByCurrency = [];
$invoiceCountsByCurrency = [];
$openInvoiceCount = 0;
$overdueInvoiceCount = 0;
foreach ($allInvoices as &$invoice) {
    $invoice['payment_summary'] = invoicePaymentSummaryFromCents(
        $invoice,
        (int) round((float) $invoice['recorded_paid'] * 100)
    );
    $invoice['display_status'] = $invoice['payment_summary']['status'];
    $currency = $invoice['currency'] ?: 'USD';

    if ($invoice['display_status'] === 'Cancelled') {
        continue;
    }

    if ($invoice['display_status'] !== 'Draft') {
        $invoicedByCurrency[$currency] = ($invoicedByCurrency[$currency] ?? 0) + max(0, (int) round((float) $invoice['total'] * 100));
        $invoiceCountsByCurrency[$currency] = ($invoiceCountsByCurrency[$currency] ?? 0) + 1;
        $paidByCurrency[$currency] = ($paidByCurrency[$currency] ?? 0) + $invoice['payment_summary']['amount_paid_cents'];
        if ($invoice['payment_summary']['balance_cents'] > 0) {
            $outstandingByCurrency[$currency] = ($outstandingByCurrency[$currency] ?? 0) + $invoice['payment_summary']['balance_cents'];
            $openInvoiceCount++;
        }
        if ($invoice['display_status'] === 'Overdue') {
            $overdueInvoiceCount++;
        }
    }
}
unset($invoice);

$averageByCurrency = [];
foreach ($invoicedByCurrency as $currency => $amountCents) {
    $averageByCurrency[$currency] = (int) round($amountCents / max(1, $invoiceCountsByCurrency[$currency]));
}

$monthStart = gmdate('Y-m-01');
$nextMonthStart = gmdate('Y-m-01', strtotime('+1 month', strtotime($monthStart)));
$stmt = $db->prepare('SELECT i.currency, SUM(p.amount) AS amount FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.business_id = :business_id AND p.payment_date >= :month_start AND p.payment_date < :next_month_start GROUP BY i.currency ORDER BY i.currency');
$stmt->execute([
    'business_id' => $business['id'],
    'month_start' => $monthStart,
    'next_month_start' => $nextMonthStart,
]);
$collectedThisMonth = [];
foreach ($stmt->fetchAll() as $row) {
    $collectedThisMonth[$row['currency'] ?: 'USD'] = (int) round((float) $row['amount'] * 100);
}

$expenseMonthStmt = $db->prepare('SELECT currency, SUM(amount) AS amount FROM expenses WHERE business_id = :business_id AND expense_date >= :month_start AND expense_date < :next_month_start GROUP BY currency ORDER BY currency');
$expenseMonthStmt->execute([
    'business_id' => (int) $business['id'],
    'month_start' => $monthStart,
    'next_month_start' => $nextMonthStart,
]);
$expensesThisMonth = [];
foreach ($expenseMonthStmt->fetchAll() as $row) {
    $expensesThisMonth[$row['currency'] ?: 'USD'] = (int) round((float) $row['amount'] * 100);
}
$netCashThisMonth = [];
foreach (array_unique(array_merge(array_keys($collectedThisMonth), array_keys($expensesThisMonth))) as $currency) {
    $netCashThisMonth[$currency] = ($collectedThisMonth[$currency] ?? 0) - ($expensesThisMonth[$currency] ?? 0);
}

$formatCurrencyGroups = static function (array $totals, string $fallbackCurrency): string {
    if (!$totals) {
        return formatMoney(0, $fallbackCurrency);
    }
    ksort($totals);
    $formatted = [];
    foreach ($totals as $currency => $cents) {
        $formatted[] = formatMoney($cents / 100, $currency);
    }

    return implode(' · ', $formatted);
};

$recentInvoices = array_slice($allInvoices, 0, 5);

$pageTitle = 'Dashboard';
$siteTitle = 'Dashboard | Invoices Generators';
$activeNav = 'dashboard.php';
include __DIR__ . '/includes/header.php';
?>

<div class="app-page">
    <section class="stats-grid">
        <article class="stat-card metric-card">
            <div class="metric-header">
                <span class="metric-title">Total invoices</span>
                <span class="metric-icon">▣</span>
            </div>
            <strong><?= e((string) $totalInvoices); ?></strong>
            <span class="metric-change"><?= e((string) $openInvoiceCount); ?> open</span>
        </article>
        <article class="stat-card metric-card">
            <div class="metric-header">
                <span class="metric-title">Collected</span>
                <span class="metric-icon">✓</span>
            </div>
            <strong><?= e($formatCurrencyGroups($paidByCurrency, $business['currency'] ?? 'USD')); ?></strong>
            <span class="metric-change">Across issued invoices</span>
        </article>
        <article class="stat-card metric-card">
            <div class="metric-header">
                <span class="metric-title">Outstanding</span>
                <span class="metric-icon">↗</span>
            </div>
            <strong><?= e($formatCurrencyGroups($outstandingByCurrency, $business['currency'] ?? 'USD')); ?></strong>
            <span class="metric-change negative"><?= e((string) $overdueInvoiceCount); ?> overdue</span>
        </article>
        <article class="stat-card metric-card">
            <div class="metric-header">
                <span class="metric-title">Customers</span>
                <span class="metric-icon">◎</span>
            </div>
            <strong><?= e((string) $totalCustomers); ?></strong>
            <span class="metric-change"><?= e((string) $totalProducts); ?> products</span>
        </article>
    </section>

    <section class="card">
        <div class="card-head">
            <h3>Performance snapshot</h3>
            <a href="invoices.php" class="btn btn-secondary">View all</a>
        </div>
        <div class="kpi-row">
            <div class="kpi">
                <small>Revenue this month</small>
                <strong><?= e($formatCurrencyGroups($collectedThisMonth, $business['currency'] ?? 'USD')); ?></strong>
            </div>
            <div class="kpi">
                <small>Products</small>
                <strong><?= e((string) $totalProducts); ?></strong>
            </div>
            <div class="kpi">
                <small>Open invoices</small>
                <strong><?= e((string) $openInvoiceCount); ?></strong>
            </div>
            <div class="kpi">
                <small>Avg. invoice</small>
                <strong><?= e($formatCurrencyGroups($averageByCurrency, $business['currency'] ?? 'USD')); ?></strong>
            </div>
            <div class="kpi">
                <small>Expenses this month</small>
                <strong><?= e($formatCurrencyGroups($expensesThisMonth, $business['currency'] ?? 'USD')); ?></strong>
                <a class="muted-copy" href="expenses.php">Manage expenses</a>
            </div>
            <div class="kpi">
                <small>Net cash movement</small>
                <strong><?= e($formatCurrencyGroups($netCashThisMonth, $business['currency'] ?? 'USD')); ?></strong>
                <a class="muted-copy" href="reports.php">Open reports</a>
            </div>
        </div>
    </section>

    <section class="card ai-business-summary no-print" data-ai-assist data-ai-widget="summarize_business" aria-labelledby="ai-business-title">
        <div class="card-head">
            <div>
                <span class="ai-assist-kicker">GEN AI</span>
                <h3 id="ai-business-title">Business invoice brief</h3>
                <p>Ask for a plain-language snapshot of paid, outstanding, and overdue invoices.</p>
            </div>
            <button class="btn btn-primary" type="button" data-ai-generate>Summarize invoices</button>
        </div>
        <p class="ai-assist-status" data-ai-status role="status" aria-live="polite"></p>
        <div class="ai-assist-preview ai-business-summary-result" data-ai-preview hidden>
            <div class="ai-assist-generated-text">
                <p data-ai-result-text></p>
            </div>
            <div class="ai-assist-preview-actions">
                <button class="btn btn-secondary" type="button" data-ai-apply>Copy summary</button>
                <button class="btn btn-secondary" type="button" data-ai-dismiss>Dismiss</button>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h3>Recent invoices</h3>
            <a href="templates.php" class="btn btn-primary">New invoice</a>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentInvoices as $invoice): ?>
                        <tr>
                            <td><a href="invoice.php?id=<?= (int) $invoice['id']; ?>"><?= e($invoice['invoice_number']); ?></a></td>
                            <td><?= e($invoice['customer_name'] ?: 'Direct Customer'); ?></td>
                            <td><span class="status-label status-<?= e(strtolower(str_replace(' ', '-', $invoice['display_status']))); ?>"><?= e($invoice['display_status']); ?></span></td>
                            <td>
                                <?= e(formatMoney((float) $invoice['total'], $invoice['currency'] ?? 'USD')); ?>
                                <small class="invoice-balance-hint">Balance <?= e(formatMoney($invoice['payment_summary']['balance_cents'] / 100, $invoice['currency'] ?? 'USD')); ?></small>
                            </td>
                            <td><?= e($invoice['due_date'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
