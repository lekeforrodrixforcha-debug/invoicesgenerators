<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}

$categories = ['Advertising', 'Bank fees', 'Equipment', 'Meals', 'Office', 'Professional services', 'Software', 'Supplies', 'Travel', 'Utilities', 'Other'];
$errorMessage = null;
$successMessage = getFlash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receiptPath = null;
    try {
        verifyCsrf();
        $expenseDate = trim((string) ($_POST['expense_date'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        if (!isValidInvoiceDate($expenseDate) || !in_array($category, $categories, true)
            || $description === '' || strlen($description) > 160 || !is_numeric($amountInput)) {
            throw new InvalidArgumentException('Enter a valid date, category, description and amount.');
        }
        $amount = (float) $amountInput;
        if (!is_finite($amount) || $amount <= 0 || $amount > 1000000000) {
            throw new InvalidArgumentException('Enter an expense amount greater than zero and within the supported range.');
        }

        $receiptPath = saveExpenseReceiptUpload($_FILES['receipt'] ?? [], (int) $business['id']);
        $insert = $db->prepare('INSERT INTO expenses (business_id, expense_date, category, description, amount, currency, receipt_path) VALUES (:business_id, :expense_date, :category, :description, :amount, :currency, :receipt_path)');
        $insert->execute([
            'business_id' => (int) $business['id'],
            'expense_date' => $expenseDate,
            'category' => $category,
            'description' => $description,
            'amount' => round($amount, 2),
            'currency' => (string) $business['currency'],
            'receipt_path' => $receiptPath,
        ]);
        setFlash('success', 'Expense recorded.');
        redirect('expenses.php');
    } catch (Throwable $exception) {
        if ($receiptPath !== null) {
            $absolutePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $receiptPath);
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }
        }
        if ($exception instanceof InvalidArgumentException) {
            $errorMessage = $exception->getMessage();
        } else {
            error_log('Expense save failed: ' . $exception->getMessage());
            $errorMessage = 'The expense could not be saved. Please try again.';
        }
    }
}

$fromDate = trim((string) ($_GET['from'] ?? gmdate('Y-m-01')));
$toDate = trim((string) ($_GET['to'] ?? gmdate('Y-m-d')));
if (!isValidInvoiceDate($fromDate) || !isValidInvoiceDate($toDate) || $fromDate > $toDate) {
    $fromDate = gmdate('Y-m-01');
    $toDate = gmdate('Y-m-d');
}
$expenseStmt = $db->prepare('SELECT * FROM expenses WHERE business_id = :business_id AND expense_date >= :from_date AND expense_date <= :to_date ORDER BY expense_date DESC, id DESC');
$expenseStmt->execute(['business_id' => (int) $business['id'], 'from_date' => $fromDate, 'to_date' => $toDate]);
$expenses = $expenseStmt->fetchAll();
$totalByCurrency = [];
foreach ($expenses as $expense) {
    $currency = (string) ($expense['currency'] ?: 'USD');
    $totalByCurrency[$currency] = ($totalByCurrency[$currency] ?? 0) + (int) round((float) $expense['amount'] * 100);
}

$pageTitle = 'Expenses';
$siteTitle = 'Expenses | Invoices Generators';
$activeNav = 'expenses.php';
include __DIR__ . '/includes/header.php';
?>
<div class="app-page">
    <?php if ($successMessage): ?><div class="notice notice-success"><?= e($successMessage); ?></div><?php endif; ?>
    <?php if ($errorMessage): ?><div class="notice notice-error"><?= e($errorMessage); ?></div><?php endif; ?>
    <section class="card">
        <div class="card-head"><div><h2>Record an expense</h2><p>Keep expenses separate from invoiced revenue; totals use <?= e((string) $business['currency']); ?>.</p></div></div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <div class="form-grid">
                <div class="form-group"><label for="expense-date">Date</label><input id="expense-date" type="date" name="expense_date" value="<?= e(gmdate('Y-m-d')); ?>" required></div>
                <div class="form-group"><label for="expense-category">Category</label><select id="expense-category" name="category" required><?php foreach ($categories as $category): ?><option value="<?= e($category); ?>"><?= e($category); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="expense-amount">Amount (<?= e((string) $business['currency']); ?>)</label><input id="expense-amount" type="number" name="amount" min="0.01" max="1000000000" step="0.01" required></div>
                <div class="form-group"><label for="expense-description">Description</label><input id="expense-description" name="description" maxlength="160" required></div>
                <div class="form-group"><label for="expense-receipt">Receipt (optional)</label><input id="expense-receipt" type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"><small>PDF, JPG, PNG or WebP; maximum 5 MB.</small></div>
            </div>
            <button class="btn btn-primary" type="submit">Save expense</button>
        </form>
    </section>
    <section class="stats-grid">
        <?php foreach ($totalByCurrency ?: [(string) $business['currency'] => 0] as $currency => $amountCents): ?>
            <article class="stat-card metric-card"><span class="metric-title">Expenses · <?= e($currency); ?></span><strong><?= e(formatMoney($amountCents / 100, $currency)); ?></strong><span class="metric-change"><?= count($expenses); ?> records in selected period</span></article>
        <?php endforeach; ?>
    </section>
    <section class="card">
        <div class="card-head"><div><h2>Expense history</h2><p>Filter by date to review recorded spending.</p></div><a class="btn btn-secondary" href="reports.php?from=<?= rawurlencode($fromDate); ?>&amp;to=<?= rawurlencode($toDate); ?>">Open reports</a></div>
        <form method="get" class="inline-actions"><div class="form-group"><label for="expense-from">From</label><input id="expense-from" type="date" name="from" value="<?= e($fromDate); ?>"></div><div class="form-group"><label for="expense-to">To</label><input id="expense-to" type="date" name="to" value="<?= e($toDate); ?>"></div><button class="btn btn-secondary" type="submit">Apply dates</button></form>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Receipt</th></tr></thead><tbody>
        <?php if (!$expenses): ?><tr><td colspan="5">No expenses in this date range.</td></tr><?php endif; ?>
        <?php foreach ($expenses as $expense): ?><tr><td><?= e($expense['expense_date']); ?></td><td><?= e($expense['category']); ?></td><td><?= e($expense['description']); ?></td><td><?= e(formatMoney((float) $expense['amount'], (string) $expense['currency'])); ?></td><td><?php if ($expense['receipt_path']): ?><a class="btn btn-secondary" href="expense_receipt.php?id=<?= (int) $expense['id']; ?>">Download</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
