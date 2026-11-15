<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}

$invoiceId = (int) ($_GET['id'] ?? 0);
$invoice = getInvoice($db, $invoiceId, $business['id']);
if (!$invoice) {
    redirect('invoices.php');
}
$isProforma = ($invoice['document_kind'] ?? 'invoice') === 'proforma';

$paymentError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    try {
        verifyCsrf();

        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amountInput)) {
            throw new InvalidArgumentException('Enter a payment amount with up to two decimal places.');
        }
        $amountCents = (int) round((float) $amountInput * 100);
        if ($amountCents < 1) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
        $parsedPaymentDate = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        if (!$parsedPaymentDate || $parsedPaymentDate->format('Y-m-d') !== $paymentDate) {
            throw new InvalidArgumentException('Choose a valid payment date.');
        }

        $paymentMethods = ['bank_transfer', 'cash', 'card', 'mobile_money', 'other'];
        $paymentMethod = (string) ($_POST['method'] ?? '');
        if (!in_array($paymentMethod, $paymentMethods, true)) {
            throw new InvalidArgumentException('Choose a valid payment method.');
        }

        $paymentReference = trim((string) ($_POST['reference'] ?? ''));
        $paymentNotes = trim((string) ($_POST['notes'] ?? ''));
        if (strlen($paymentReference) > 120 || strlen($paymentNotes) > 500) {
            throw new InvalidArgumentException('Reference must be 120 characters or fewer and notes 500 characters or fewer.');
        }

        $db->beginTransaction();
        $currentInvoice = getInvoice($db, $invoiceId, (int) $business['id']);
        if (!$currentInvoice) {
            throw new InvalidArgumentException('This invoice is no longer available.');
        }
        if (($currentInvoice['document_kind'] ?? 'invoice') === 'proforma') {
            throw new InvalidArgumentException('Payments cannot be recorded for an existing pro forma document.');
        }
        if ($currentInvoice['status'] === 'Draft' || $currentInvoice['status'] === 'Cancelled') {
            throw new InvalidArgumentException('Payments can only be recorded for issued invoices.');
        }

        $currentSummary = getInvoicePaymentSummary($db, $currentInvoice);
        if (!in_array($currentSummary['status'], ['Issued', 'Overdue', 'Partially Paid'], true)) {
            throw new InvalidArgumentException('This invoice has no remaining balance.');
        }
        if ($amountCents > $currentSummary['balance_cents']) {
            throw new InvalidArgumentException('Payment exceeds the remaining balance of ' . formatMoney($currentSummary['balance_cents'] / 100, $currentInvoice['currency']) . '.');
        }

        $paymentStmt = $db->prepare('INSERT INTO invoice_payments (invoice_id, amount, payment_date, method, reference, notes) VALUES (:invoice_id, :amount, :payment_date, :method, :reference, :notes)');
        $paymentStmt->execute([
            'invoice_id' => $invoiceId,
            'amount' => $amountCents / 100,
            'payment_date' => $paymentDate,
            'method' => $paymentMethod,
            'reference' => $paymentReference !== '' ? $paymentReference : null,
            'notes' => $paymentNotes !== '' ? $paymentNotes : null,
        ]);
        $nextPaymentSummary = invoicePaymentSummaryFromCents(
            $currentInvoice,
            $currentSummary['amount_paid_cents'] + $amountCents
        );
        recordInvoiceEvent(
            $db,
            $invoiceId,
            'payment_recorded',
            $currentSummary['status'],
            $nextPaymentSummary['status'],
            ['amount_cents' => $amountCents, 'payment_date' => $paymentDate, 'method' => $paymentMethod]
        );
        $db->commit();

        setFlash('success', 'Payment recorded successfully.');
        redirect('invoice.php?id=' . $invoiceId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $paymentError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['issue_invoice', 'cancel_invoice'], true)) {
    try {
        verifyCsrf();
        $action = (string) $_POST['action'];
        if ($action === 'issue_invoice' && $isProforma) {
            throw new InvalidArgumentException('Existing pro forma documents cannot be issued as invoices.');
        }
        $transition = transitionInvoiceStatus($db, $invoiceId, (int) $business['id'], $action);

        setFlash('success', $transition['to_status'] === 'Issued' ? 'Invoice issued successfully.' : 'Invoice cancelled.');
        redirect('invoice.php?id=' . $invoiceId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $paymentError = $e->getMessage();
    }
}

$paymentSummary = getInvoicePaymentSummary($db, $invoice);
$invoiceDisplayStatus = $paymentSummary['status'];
$paymentSuccess = getFlash('success');
$portalLink = getFlash('portal_link');
$portalTokenStmt = $db->prepare('SELECT id FROM customer_portal_tokens WHERE invoice_id = :invoice_id AND revoked_at IS NULL');
$portalTokenStmt->execute(['invoice_id' => $invoiceId]);
$hasPortalLink = $portalTokenStmt->fetchColumn() !== false;

$paymentStmt = $db->prepare('SELECT * FROM invoice_payments WHERE invoice_id = :invoice_id ORDER BY payment_date DESC, id DESC');
$paymentStmt->execute(['invoice_id' => $invoiceId]);
$payments = $paymentStmt->fetchAll();

$eventsStmt = $db->prepare('SELECT event_type, from_status, to_status, details_json, created_at FROM invoice_events WHERE invoice_id = :invoice_id ORDER BY created_at DESC, id DESC');
$eventsStmt->execute(['invoice_id' => $invoiceId]);
$invoiceEvents = $eventsStmt->fetchAll();
$canCancelInvoice = in_array($invoice['status'], ['Draft', 'Issued'], true) && !$payments;

$stmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :invoice_id ORDER BY id ASC');
$stmt->execute(['invoice_id' => $invoiceId]);
$items = $stmt->fetchAll();

$stmt = $db->prepare('SELECT * FROM customers WHERE id = :customer_id AND business_id = :business_id LIMIT 1');
$stmt->execute(['customer_id' => (int) $invoice['customer_id'], 'business_id' => $business['id']]);
$customer = $stmt->fetch();

$templateKey = in_array($invoice['template'] ?? 'classic', ['classic', 'modern', 'minimal', 'executive', 'creative', 'editorial', 'ledger', 'atelier', 'horizon', 'studio', 'cobalt', 'terra', 'noir', 'botanical', 'aurora', 'blueprint', 'sunrise', 'monochrome', 'coastal', 'gilded'], true)
    ? $invoice['template']
    : 'classic';
$issuerName = $invoice['issuer_name'] ?? $business['name'];
$issuerEmail = $invoice['issuer_email'] ?? ($business['email'] ?? '');
$issuerPhone = $invoice['issuer_phone'] ?? ($business['phone'] ?? '');
$issuerAddress = $invoice['issuer_address'] ?? ($business['address'] ?? '');
$issuerCity = $invoice['issuer_city'] ?? ($business['city'] ?? '');
$issuerCountry = $invoice['issuer_country'] ?? ($business['country'] ?? '');
$issuerTaxId = $invoice['issuer_tax_id'] ?? ($business['tax_id'] ?? '');
$recipientCompany = $invoice['recipient_company'] ?? ($customer['company'] ?? '');
$recipientAddress = $invoice['recipient_address'] ?? ($customer['address'] ?? '');
$recipientEmail = $invoice['recipient_email'] ?? ($customer['email'] ?? '');
$recipientPhone = $invoice['recipient_phone'] ?? ($customer['phone'] ?? '');
$documentLabel = $invoice['document_label'] ?? 'PAYMENT REQUEST';
$documentTitle = $invoice['document_title'] ?? 'Invoice';
$businessInitial = strtoupper(substr(trim((string) $issuerName), 0, 1));
$invoiceLogoPath = is_string($invoice['logo_path'] ?? null) && $invoice['logo_path'] !== ''
    ? $invoice['logo_path']
    : ($business['logo_path'] ?? null);
$hasInvoiceLogo = resolveBusinessLogoPath(is_string($invoiceLogoPath) ? $invoiceLogoPath : null, (int) $business['id']) !== null;

$pageTitle = 'Invoice detail';
$siteTitle = 'Invoice detail | Invoices Generators';
$activeNav = 'invoices.php';
include __DIR__ . '/includes/header.php';
$redirectError = getFlash('error');
?>

<div class="app-page">
    <?php if ($redirectError): ?>
        <div class="notice notice-error"><?= e($redirectError); ?></div>
    <?php endif; ?>
    <?php if ($paymentSuccess && !$portalLink): ?>
        <div class="notice notice-success"><?= e($paymentSuccess); ?></div>
    <?php endif; ?>
    <?php if ($paymentError !== null): ?>
        <div class="notice notice-error"><?= e($paymentError); ?></div>
    <?php endif; ?>
    <?php if ($invoiceDisplayStatus !== 'Paid'): ?>
        <section class="ai-invoice-assist no-print" data-ai-assist data-ai-widget="reminder" data-invoice-id="<?= (int) $invoiceId; ?>" aria-labelledby="ai-reminder-title">
            <div class="ai-invoice-assist-copy">
                <span class="ai-assist-kicker">GEN AI</span>
                <h4 id="ai-reminder-title">Draft a payment reminder</h4>
                <p>Prepare a polite message for <?= e($customer['name'] ?? 'your client'); ?>. Review and copy it to send yourself.</p>
            </div>
            <button class="btn btn-primary ai-reminder-generate" type="button" data-ai-generate>Draft reminder</button>
            <p class="ai-assist-status" data-ai-status role="status" aria-live="polite"></p>
            <div class="ai-assist-preview ai-reminder-preview" data-ai-preview hidden>
                <div class="ai-assist-generated-text">
                    <strong data-ai-subject></strong>
                    <p data-ai-result-text></p>
                </div>
                <div class="ai-assist-preview-actions">
                    <button class="btn btn-primary" type="button" data-ai-apply>Copy reminder</button>
                    <button class="btn btn-secondary" type="button" data-ai-dismiss>Dismiss</button>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="card payment-management no-print" aria-labelledby="payments-title">
        <div class="card-head">
            <div>
                <h3 id="payments-title">Payments and balance</h3>
                <p><span class="status-label status-<?= e(strtolower(str_replace(' ', '-', $invoiceDisplayStatus))); ?>"><?= e($invoiceDisplayStatus); ?></span></p>
            </div>
            <div class="inline-actions invoice-actions">
                <?php if ($invoice['status'] === 'Draft' && !$isProforma): ?>
                    <form class="invoice-primary-action" method="post" action="invoice.php?id=<?= (int) $invoiceId; ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                        <input type="hidden" name="action" value="issue_invoice">
                        <button class="btn btn-primary" type="submit">Issue invoice</button>
                    </form>
                    <a class="btn btn-secondary" href="invoice_pdf.php?id=<?= (int) $invoiceId; ?>">Download PDF</a>
                <?php else: ?>
                    <a class="btn btn-primary" href="invoice_pdf.php?id=<?= (int) $invoiceId; ?>">Download PDF</a>
                <?php endif; ?>
                <details class="invoice-more-actions quote-more-actions">
                    <summary class="btn btn-secondary">More</summary>
                    <div class="quote-more-menu">
                        <?php if ($invoice['status'] === 'Draft' && !$isProforma): ?>
                            <a class="btn btn-secondary" href="invoice_create.php?edit=<?= (int) $invoiceId; ?>">Edit draft</a>
                        <?php endif; ?>
                        <?php if ($invoice['status'] !== 'Cancelled'): ?>
                            <a class="btn btn-secondary" href="invoice_create.php?duplicate=<?= (int) $invoiceId; ?>">Duplicate</a>
                        <?php endif; ?>
                        <?php if ($hasPortalLink): ?>
                            <form method="post" action="portal_link.php">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                                <input type="hidden" name="document_type" value="invoice">
                                <input type="hidden" name="document_id" value="<?= (int) $invoiceId; ?>">
                                <input type="hidden" name="action" value="revoke">
                                <button class="btn btn-secondary" type="submit">Revoke customer link</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canCancelInvoice): ?>
                            <form method="post" action="invoice.php?id=<?= (int) $invoiceId; ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                                <input type="hidden" name="action" value="cancel_invoice">
                                <button class="btn btn-secondary" type="submit">Cancel invoice</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
            <div class="payment-balance-summary">
                <span>Paid <?= e(formatMoney($paymentSummary['amount_paid_cents'] / 100, $invoice['currency'])); ?></span>
                <strong>Balance <?= e(formatMoney($paymentSummary['balance_cents'] / 100, $invoice['currency'])); ?></strong>
            </div>
        </div>

        <details class="payment-details"<?= $paymentError !== null || $paymentSuccess ? ' open' : ''; ?>>
            <summary>
                <span>View all payment details</span>
                <span class="payment-details-count"><?= count($payments); ?> <?= count($payments) === 1 ? 'payment' : 'payments'; ?></span>
            </summary>
            <div class="payment-details-content">
                <?php if (!$isProforma && in_array($invoiceDisplayStatus, ['Issued', 'Overdue', 'Partially Paid'], true)): ?>
                    <form method="post" action="invoice.php?id=<?= (int) $invoiceId; ?>" class="payment-entry-form">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                        <input type="hidden" name="action" value="record_payment">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="payment-amount">Amount (<?= e($invoice['currency']); ?>)</label>
                                <input id="payment-amount" type="number" name="amount" min="0.01" max="<?= e(number_format($paymentSummary['balance_cents'] / 100, 2, '.', '')); ?>" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label for="payment-date">Payment date</label>
                                <input id="payment-date" type="date" name="payment_date" value="<?= e(gmdate('Y-m-d')); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="payment-method">Method</label>
                                <select id="payment-method" name="method" required>
                                    <option value="bank_transfer">Bank transfer</option>
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="mobile_money">Mobile money</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="payment-reference">Reference (optional)</label>
                                <input id="payment-reference" type="text" name="reference" maxlength="120">
                            </div>
                            <div class="form-group payment-notes-field">
                                <label for="payment-notes">Notes (optional)</label>
                                <input id="payment-notes" type="text" name="notes" maxlength="500">
                            </div>
                        </div>
                        <button class="btn btn-primary" type="submit">Record payment</button>
                    </form>
                <?php endif; ?>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Date</th><th>Method</th><th>Reference / notes</th><th>Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($payments): ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?= e($payment['payment_date']); ?></td>
                                        <td><?= e(ucwords(str_replace('_', ' ', $payment['method']))); ?></td>
                                        <td><?= e(implode(' · ', array_filter([$payment['reference'], $payment['notes']])) ?: '—'); ?></td>
                                        <td><span><?= e(formatMoney((float) $payment['amount'], $invoice['currency'])); ?></span> <a class="btn btn-secondary" href="receipt_pdf.php?id=<?= (int) $payment['id']; ?>">Receipt PDF</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php elseif ($invoice['status'] === 'Paid'): ?>
                                <tr><td colspan="4">Marked paid before payment tracking was added; no payment details were recorded.</td></tr>
                            <?php else: ?>
                                <tr><td colspan="4">No payments recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    </section>

    <?php if ($invoice['status'] !== 'Cancelled' && !$isProforma): ?>
        <?php if ($portalLink): ?>
            <div class="customer-link-notice">
                <div class="customer-link-copy">
                    <strong>Customer link is ready</strong>
                    <span>Share this link with your customer. It remains active until you revoke it.</span>
                </div>
                <div class="customer-link-actions">
                    <button class="btn btn-primary" type="button" data-copy-customer-link>Copy link</button>
                    <a class="customer-link-open" data-customer-link href="<?= e($portalLink); ?>" target="_blank" rel="noopener">Open link</a>
                </div>
                <span class="customer-link-copy-status" data-copy-customer-link-status role="status" aria-live="polite"></span>
            </div>
        <?php else: ?>
            <div class="customer-link-notice">
                <div class="customer-link-copy">
                    <strong><?= $hasPortalLink ? 'Customer link is active' : 'Share this invoice'; ?></strong>
                    <span><?= $hasPortalLink ? 'Create a new link to copy it; this will invalidate the current link.' : 'Create a secure link to share this invoice with your customer.'; ?></span>
                </div>
                <form class="customer-link-actions" method="post" action="portal_link.php">
                    <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                    <input type="hidden" name="document_type" value="invoice">
                    <input type="hidden" name="document_id" value="<?= (int) $invoiceId; ?>">
                    <input type="hidden" name="action" value="create">
                    <button class="btn btn-primary" type="submit"><?= $hasPortalLink ? 'Get new customer link' : 'Share with customer'; ?></button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <section class="card">
        <div class="card-head no-print">
            <h3>Invoice preview</h3>
            <div class="inline-actions">
                <a class="btn btn-secondary" href="invoice_pdf.php?id=<?= (int) $invoiceId; ?>">Download PDF</a>
                <a class="btn btn-primary" href="templates.php">New invoice</a>
            </div>
        </div>

        <div class="invoice-document template-<?= e($templateKey); ?>">
            <header class="invoice-document-header">
                <div class="invoice-issuer">
                    <span class="invoice-logo<?= $hasInvoiceLogo ? ' has-image' : ''; ?>">
                        <span <?= $hasInvoiceLogo ? 'hidden' : ''; ?>><?= e($businessInitial ?: 'I'); ?></span>
                        <?php if ($hasInvoiceLogo): ?><img src="logo.php?invoice=<?= (int) $invoiceId; ?>" alt="<?= e($issuerName); ?> logo"><?php endif; ?>
                    </span>
                    <span>
                        <strong><?= e($issuerName); ?></strong>
                        <small><?= e($issuerEmail); ?></small>
                    </span>
                </div>
                <div class="invoice-heading">
                    <span class="invoice-kicker"><?= e($documentLabel); ?></span>
                    <h1><?= e($documentTitle); ?></h1>
                    <strong><?= e($invoice['invoice_number']); ?></strong>
                </div>
            </header>

            <div class="invoice-parties">
                <section>
                    <span class="invoice-label">FROM</span>
                    <strong><?= e($issuerName); ?></strong>
                    <?php if ($issuerAddress !== ''): ?><span><?= e($issuerAddress); ?></span><?php endif; ?>
                    <?php if ($issuerCity !== '' || $issuerCountry !== ''): ?>
                        <span><?= e(implode(', ', array_filter([$issuerCity, $issuerCountry]))); ?></span>
                    <?php endif; ?>
                    <?php if ($issuerEmail !== ''): ?><span><?= e($issuerEmail); ?></span><?php endif; ?>
                    <?php if ($issuerPhone !== ''): ?><span><?= e($issuerPhone); ?></span><?php endif; ?>
                    <?php if ($issuerTaxId !== ''): ?><span>Tax ID <?= e($issuerTaxId); ?></span><?php endif; ?>
                </section>
                <section>
                    <span class="invoice-label">BILL TO</span>
                    <strong><?= e($customer['name'] ?? 'Client'); ?></strong>
                    <?php if ($recipientCompany !== ''): ?><span><?= e($recipientCompany); ?></span><?php endif; ?>
                    <?php if ($recipientAddress !== ''): ?><span><?= e($recipientAddress); ?></span><?php endif; ?>
                    <?php if ($recipientEmail !== ''): ?><span><?= e($recipientEmail); ?></span><?php endif; ?>
                    <?php if ($recipientPhone !== ''): ?><span><?= e($recipientPhone); ?></span><?php endif; ?>
                </section>
                <section class="invoice-dates">
                    <div><span class="invoice-label">ISSUED</span><strong><?= e(date('M j, Y', strtotime($invoice['issue_date']))); ?></strong></div>
                    <div><span class="invoice-label">DUE DATE</span><strong><?= e($invoice['due_date'] ? date('M j, Y', strtotime($invoice['due_date'])) : 'Upon receipt'); ?></strong></div>
                    <div><span class="invoice-label">STATUS</span><strong class="invoice-status status-<?= e(strtolower(str_replace(' ', '-', $invoiceDisplayStatus))); ?>"><?= e($invoiceDisplayStatus); ?></strong></div>
                </section>
            </div>

            <div class="invoice-items-wrap">
                <table class="invoice-items">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Tax</th>
                            <th class="invoice-number-cell">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?= e($item['description']); ?></strong></td>
                                <td><?= e(rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.')); ?></td>
                                <td><?= e(formatMoney((float) $item['unit_price'], $invoice['currency'] ?? 'USD')); ?></td>
                                <td><?= e((string) $item['tax_rate']); ?>%</td>
                                <td class="invoice-number-cell"><?= e(formatMoney((float) $item['line_total'], $invoice['currency'] ?? 'USD')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="invoice-summary-row">
                <div class="invoice-message">
                    <?php if (!empty($invoice['notes'])): ?>
                        <span class="invoice-label">A NOTE</span>
                        <p><?= e($invoice['notes']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['terms'])): ?>
                        <span class="invoice-label">PAYMENT TERMS</span>
                        <p><?= e($invoice['terms']); ?></p>
                    <?php endif; ?>
                    <?php if (empty($invoice['notes']) && empty($invoice['terms'])): ?>
                        <span class="invoice-label">THANK YOU</span>
                        <p>Thank you for your business.</p>
                    <?php endif; ?>
                </div>
                <div class="invoice-totals">
                    <div><span>Subtotal</span><strong><?= e(formatMoney((float) $invoice['subtotal'], $invoice['currency'] ?? 'USD')); ?></strong></div>
                    <?php if ((float) $invoice['discount'] > 0): ?>
                        <div><span>Discount</span><strong>−<?= e(formatMoney((float) $invoice['discount'], $invoice['currency'] ?? 'USD')); ?></strong></div>
                    <?php endif; ?>
                    <div><span>Tax</span><strong><?= e(formatMoney((float) $invoice['tax'], $invoice['currency'] ?? 'USD')); ?></strong></div>
                    <div class="invoice-grand-total"><span>Total due</span><strong><?= e(formatMoney((float) $invoice['total'], $invoice['currency'] ?? 'USD')); ?></strong></div>
                </div>
            </div>

            <footer class="invoice-document-footer">
                <span><?= e($issuerName); ?></span>
                <span><?= e($issuerEmail ?: $issuerPhone); ?></span>
            </footer>
        </div>
    </section>

    <section class="card invoice-history no-print" aria-labelledby="invoice-history-title">
        <div class="card-head"><h3 id="invoice-history-title">Activity history</h3></div>
        <?php if ($invoiceEvents): ?>
            <ol class="invoice-event-list">
                <?php foreach ($invoiceEvents as $event): ?>
                    <?php
                    $eventLabels = [
                        'invoice_created' => 'Invoice created',
                        'draft_edited' => 'Draft updated',
                        'issue_invoice' => 'Invoice issued',
                        'cancel_invoice' => 'Invoice cancelled',
                        'payment_recorded' => 'Payment recorded',
                    ];
                    $eventDetails = $event['details_json'] ? json_decode($event['details_json'], true) : [];
                    $eventDetails = is_array($eventDetails) ? $eventDetails : [];
                    ?>
                    <li>
                        <div>
                            <strong><?= e($eventLabels[$event['event_type']] ?? ucwords(str_replace('_', ' ', $event['event_type']))); ?></strong>
                            <?php if ($event['from_status'] !== null || $event['to_status'] !== null): ?>
                                <span><?= e(($event['from_status'] ?: 'New') . ' → ' . ($event['to_status'] ?: '')); ?></span>
                            <?php endif; ?>
                            <?php if (isset($eventDetails['amount_cents'])): ?>
                                <span><?= e(formatMoney(((int) $eventDetails['amount_cents']) / 100, $invoice['currency'])); ?> · <?= e(ucwords(str_replace('_', ' ', (string) ($eventDetails['method'] ?? '')))); ?></span>
                            <?php endif; ?>
                        </div>
                        <time datetime="<?= e($event['created_at']); ?>"><?= e(gmdate('Y-m-d H:i \U\T\C', strtotime($event['created_at']))); ?></time>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p class="muted-copy">No activity history is available for this invoice.</p>
        <?php endif; ?>
    </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
