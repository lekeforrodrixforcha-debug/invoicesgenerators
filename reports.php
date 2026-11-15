<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}
$planEntitlements = getPlanEntitlements($db, (int) $_SESSION['user_id']);
$hasAdvancedReports = (bool) $planEntitlements['plan']['analytics'];
$fromDate = $hasAdvancedReports ? trim((string) ($_GET['from'] ?? gmdate('Y-m-01'))) : gmdate('Y-m-01');
$toDate = $hasAdvancedReports ? trim((string) ($_GET['to'] ?? gmdate('Y-m-d'))) : gmdate('Y-m-d');
$validRange = isValidInvoiceDate($fromDate) && isValidInvoiceDate($toDate) && $fromDate <= $toDate;
if (!$validRange) {
    $fromDate = gmdate('Y-m-01');
    $toDate = gmdate('Y-m-d');
}

$collectedStmt = $db->prepare('SELECT i.currency, SUM(p.amount) AS amount FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.business_id = :business_id AND p.payment_date >= :from_date AND p.payment_date <= :to_date GROUP BY i.currency ORDER BY i.currency');
$collectedStmt->execute(['business_id' => (int) $business['id'], 'from_date' => $fromDate, 'to_date' => $toDate]);
$collectedByCurrency = [];
foreach ($collectedStmt->fetchAll() as $row) {
    $collectedByCurrency[(string) ($row['currency'] ?: 'USD')] = (int) round((float) $row['amount'] * 100);
}

$expensesStmt = $db->prepare('SELECT currency, SUM(amount) AS amount FROM expenses WHERE business_id = :business_id AND expense_date >= :from_date AND expense_date <= :to_date GROUP BY currency ORDER BY currency');
$expensesStmt->execute(['business_id' => (int) $business['id'], 'from_date' => $fromDate, 'to_date' => $toDate]);
$expensesByCurrency = [];
foreach ($expensesStmt->fetchAll() as $row) {
    $expensesByCurrency[(string) ($row['currency'] ?: 'USD')] = (int) round((float) $row['amount'] * 100);
}

$outstandingByCurrency = [];
$overdueCount = 0;
$expenseCategories = [];
$monthlyTotals = [];
$trendMonths = [];
$topCustomers = [];
if ($hasAdvancedReports) {
    $categoryStmt = $db->prepare('SELECT category, currency, SUM(amount) AS amount, COUNT(*) AS expense_count FROM expenses WHERE business_id = :business_id AND expense_date >= :from_date AND expense_date <= :to_date GROUP BY category, currency ORDER BY amount DESC, category');
    $categoryStmt->execute(['business_id' => (int) $business['id'], 'from_date' => $fromDate, 'to_date' => $toDate]);
    $expenseCategories = $categoryStmt->fetchAll();

    $currentMonth = gmdate('Y-m-01');
    $trendStart = gmdate('Y-m-01', strtotime('-5 months', strtotime($currentMonth)));
    $trendEnd = gmdate('Y-m-01', strtotime('+1 month', strtotime($currentMonth)));
    $paymentMonthExpression = databaseMonthExpression(databaseDriver($db), 'p.payment_date');
    $expenseMonthExpression = databaseMonthExpression(databaseDriver($db), 'expense_date');
    $trendCollectedStmt = $db->prepare("SELECT {$paymentMonthExpression} AS month, i.currency, SUM(p.amount) AS amount FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id WHERE i.business_id = :business_id AND p.payment_date >= :trend_start AND p.payment_date < :trend_end GROUP BY month, i.currency ORDER BY month DESC, i.currency");
    $trendCollectedStmt->execute(['business_id' => (int) $business['id'], 'trend_start' => $trendStart, 'trend_end' => $trendEnd]);
    foreach ($trendCollectedStmt->fetchAll() as $row) {
        $monthlyTotals[$row['month']][$row['currency'] ?: 'USD']['collected'] = (int) round((float) $row['amount'] * 100);
    }
    $trendExpensesStmt = $db->prepare("SELECT {$expenseMonthExpression} AS month, currency, SUM(amount) AS amount FROM expenses WHERE business_id = :business_id AND expense_date >= :trend_start AND expense_date < :trend_end GROUP BY month, currency ORDER BY month DESC, currency");
    $trendExpensesStmt->execute(['business_id' => (int) $business['id'], 'trend_start' => $trendStart, 'trend_end' => $trendEnd]);
    foreach ($trendExpensesStmt->fetchAll() as $row) {
        $monthlyTotals[$row['month']][$row['currency'] ?: 'USD']['expenses'] = (int) round((float) $row['amount'] * 100);
    }
    for ($offset = 0; $offset < 6; $offset++) {
        $trendMonths[] = gmdate('Y-m', strtotime('+' . $offset . ' months', strtotime($trendStart)));
    }

    $topCustomersStmt = $db->prepare("SELECT COALESCE(NULLIF(c.company, ''), c.name, 'Deleted customer') AS customer_name, i.currency, SUM(p.amount) AS collected FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id LEFT JOIN customers c ON c.id = i.customer_id WHERE i.business_id = :business_id AND p.payment_date >= :from_date AND p.payment_date <= :to_date GROUP BY customer_name, i.currency ORDER BY collected DESC, customer_name LIMIT 10");
    $topCustomersStmt->execute(['business_id' => (int) $business['id'], 'from_date' => $fromDate, 'to_date' => $toDate]);
    $topCustomers = $topCustomersStmt->fetchAll();

    $invoiceStmt = $db->prepare('SELECT i.*, COALESCE(p.amount_paid, 0) AS recorded_paid FROM invoices i LEFT JOIN (SELECT invoice_id, SUM(amount) AS amount_paid FROM invoice_payments GROUP BY invoice_id) p ON p.invoice_id = i.id WHERE i.business_id = :business_id');
    $invoiceStmt->execute(['business_id' => (int) $business['id']]);
    foreach ($invoiceStmt->fetchAll() as $invoice) {
        $summary = invoicePaymentSummaryFromCents($invoice, (int) round((float) $invoice['recorded_paid'] * 100));
        if (in_array($summary['status'], ['Draft', 'Cancelled'], true)) {
            continue;
        }
        if ($summary['balance_cents'] > 0) {
            $currency = (string) ($invoice['currency'] ?: 'USD');
            $outstandingByCurrency[$currency] = ($outstandingByCurrency[$currency] ?? 0) + $summary['balance_cents'];
        }
        if ($summary['status'] === 'Overdue' && $summary['balance_cents'] > 0) {
            $overdueCount++;
        }
    }
}

$allCurrencies = array_unique(array_merge(array_keys($collectedByCurrency), array_keys($expensesByCurrency), array_keys($outstandingByCurrency)));
sort($allCurrencies);
$pageTitle = 'Reports';
$siteTitle = 'Reports | Invoices Generators';
$activeNav = 'reports.php';
include __DIR__ . '/includes/header.php';
?>
<div class="app-page">
    <?php if ($hasAdvancedReports && !$validRange): ?><div class="notice notice-error">Choose a valid date range. Showing this month instead.</div><?php endif; ?>
    <section class="card">
        <div class="card-head"><div><h2><?= $hasAdvancedReports ? 'Business reports' : 'Monthly overview'; ?></h2><p>Collections and expenses use payment and expense dates; different currencies are never combined.</p></div></div>
        <?php if ($hasAdvancedReports): ?>
            <form method="get" class="inline-actions">
                <div class="form-group"><label for="report-from">From</label><input id="report-from" type="date" name="from" value="<?= e($fromDate); ?>" required></div>
                <div class="form-group"><label for="report-to">To</label><input id="report-to" type="date" name="to" value="<?= e($toDate); ?>" required></div>
                <button class="btn btn-primary" type="submit">Update report</button>
            </form>
        <?php else: ?>
            <p>Showing this month. Upgrade to Pro or Business to filter dates and view detailed reports.</p>
        <?php endif; ?>
    </section>
    <section class="stats-grid">
        <?php if (!$allCurrencies): $allCurrencies = [(string) ($business['currency'] ?: 'USD')]; endif; ?>
        <?php foreach ($allCurrencies as $currency): ?>
            <?php $collectedCents = $collectedByCurrency[$currency] ?? 0; $expenseCents = $expensesByCurrency[$currency] ?? 0; ?>
            <article class="stat-card metric-card"><span class="metric-title">Collected · <?= e($currency); ?></span><strong><?= e(formatMoney($collectedCents / 100, $currency)); ?></strong><span class="metric-change"><?= e($fromDate); ?> to <?= e($toDate); ?></span></article>
            <article class="stat-card metric-card"><span class="metric-title">Expenses · <?= e($currency); ?></span><strong><?= e(formatMoney($expenseCents / 100, $currency)); ?></strong><span class="metric-change"><?= e($fromDate); ?> to <?= e($toDate); ?></span></article>
            <article class="stat-card metric-card"><span class="metric-title">Net cash movement · <?= e($currency); ?></span><strong><?= e(formatMoney(($collectedCents - $expenseCents) / 100, $currency)); ?></strong><span class="metric-change">Collected minus recorded expenses</span></article>
        <?php endforeach; ?>
    </section>
    <?php if (!$hasAdvancedReports): ?>
        <section class="card">
            <h2>Unlock advanced reports</h2>
            <p>Review receivables, expense categories, six-month trends, and top customers with a Pro or Business plan.</p>
            <a class="btn btn-primary" href="billing.php">View plans</a>
        </section>
    <?php else: ?>
    <section class="card">
        <div class="card-head"><div><h2>Current receivables</h2><p>Outstanding balances as of <?= e(gmdate('Y-m-d')); ?>, independent of the selected date range.</p></div><span class="status-label status-overdue"><?= (int) $overdueCount; ?> overdue</span></div>
        <div class="inline-actions"><?php if ($outstandingByCurrency): foreach ($outstandingByCurrency as $currency => $amountCents): ?><span class="kpi"><small>Outstanding · <?= e($currency); ?></small><strong><?= e(formatMoney($amountCents / 100, $currency)); ?></strong></span><?php endforeach; else: ?><p>No outstanding invoice balances.</p><?php endif; ?></div>
    </section>
    <section class="card">
        <div class="card-head"><div><h2>Expenses by category</h2><p>Amounts remain separated by currency.</p></div><a class="btn btn-secondary" href="expenses.php">Manage expenses</a></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Category</th><th>Currency</th><th>Records</th><th>Amount</th></tr></thead><tbody>
        <?php if (!$expenseCategories): ?><tr><td colspan="4">No expenses in this date range.</td></tr><?php endif; ?>
        <?php foreach ($expenseCategories as $category): ?><tr><td><?= e($category['category']); ?></td><td><?= e($category['currency']); ?></td><td><?= (int) $category['expense_count']; ?></td><td><?= e(formatMoney((float) $category['amount'], (string) $category['currency'])); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="card">
        <div class="card-head"><div><h2>Six-month cash trend</h2><p>Collections and recorded expenses by payment/expense month and currency.</p></div></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Month</th><th>Currency</th><th>Collected</th><th>Expenses</th><th>Net cash movement</th></tr></thead><tbody>
        <?php $hasTrend = false; foreach (array_reverse($trendMonths) as $month): foreach (($monthlyTotals[$month] ?? []) as $currency => $totals): $hasTrend = true; $collected = $totals['collected'] ?? 0; $spent = $totals['expenses'] ?? 0; ?>
            <tr><td><?= e($month); ?></td><td><?= e($currency); ?></td><td><?= e(formatMoney($collected / 100, $currency)); ?></td><td><?= e(formatMoney($spent / 100, $currency)); ?></td><td><?= e(formatMoney(($collected - $spent) / 100, $currency)); ?></td></tr>
        <?php endforeach; endforeach; ?>
        <?php if (!$hasTrend): ?><tr><td colspan="5">No payment or expense activity in the last six months.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <section class="card">
        <div class="card-head"><div><h2>Top customers by collections</h2><p>Collected payments during the selected period, grouped by customer and currency.</p></div></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Customer</th><th>Currency</th><th>Collected</th></tr></thead><tbody>
        <?php if (!$topCustomers): ?><tr><td colspan="3">No customer payments in this date range.</td></tr><?php endif; ?>
        <?php foreach ($topCustomers as $customer): ?><tr><td><?= e($customer['customer_name']); ?></td><td><?= e($customer['currency']); ?></td><td><?= e(formatMoney((float) $customer['collected'], (string) $customer['currency'])); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
