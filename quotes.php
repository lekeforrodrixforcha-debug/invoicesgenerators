<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}
$planEntitlements = getPlanEntitlements($db, (int) $_SESSION['user_id']);

$errorMessage = getFlash('error');
$successMessage = getFlash('success');
$portalLink = getFlash('portal_link');
$today = gmdate('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');
        $quoteId = filter_var($_POST['quote_id'] ?? null, FILTER_VALIDATE_INT);
        $quoteId = $quoteId === false ? 0 : (int) $quoteId;
        $quote = null;
        if ($quoteId > 0) {
            $quoteStmt = $db->prepare('SELECT * FROM quotations WHERE id = :id AND business_id = :business_id');
            $quoteStmt->execute(['id' => $quoteId, 'business_id' => (int) $business['id']]);
            $quote = $quoteStmt->fetch() ?: null;
            if (!$quote) {
                throw new InvalidArgumentException('This quotation is no longer available.');
            }
        }

        if ($action === 'create') {
            assertPlanAllowsQuotations($db, (int) $_SESSION['user_id']);
            $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);
            $number = trim((string) ($_POST['quotation_number'] ?? ''));
            $issueDate = trim((string) ($_POST['issue_date'] ?? ''));
            $expiryDate = trim((string) ($_POST['expiry_date'] ?? ''));
            if ($customerId === false || $customerId < 1 || !getCustomer($db, (int) $customerId, (int) $business['id'])) {
                throw new InvalidArgumentException('Choose a customer from your business.');
            }
            if ($number === '' || strlen($number) > 80) {
                throw new InvalidArgumentException('Enter a quotation number of 1–80 characters.');
            }
            if (!isValidInvoiceDate($issueDate) || !isValidInvoiceDate($expiryDate) || $expiryDate < $issueDate) {
                throw new InvalidArgumentException('Choose valid issue and expiry dates; expiry cannot be before issue.');
            }
            $items = [];
            $descriptions = $_POST['item_description'] ?? [];
            $quantities = $_POST['item_quantity'] ?? [];
            $prices = $_POST['item_unit_price'] ?? [];
            $discounts = $_POST['item_discount'] ?? [];
            $taxRates = $_POST['item_tax_rate'] ?? [];
            if (!is_array($descriptions) || count($descriptions) > 100) {
                throw new InvalidArgumentException('A quotation can contain at most 100 items.');
            }
            foreach ($descriptions as $index => $rawDescription) {
                $description = trim((string) $rawDescription);
                $quantityRaw = trim((string) ($quantities[$index] ?? '1'));
                $priceRaw = trim((string) ($prices[$index] ?? '0'));
                $discountRaw = trim((string) ($discounts[$index] ?? '0'));
                $taxRaw = trim((string) ($taxRates[$index] ?? '0'));
                if ($description === '' && $priceRaw === '0' && $discountRaw === '0' && $taxRaw === '0') {
                    continue;
                }
                if ($description === '' || strlen($description) > 500
                    || !is_numeric($quantityRaw) || !is_numeric($priceRaw) || !is_numeric($discountRaw) || !is_numeric($taxRaw)) {
                    throw new InvalidArgumentException('Complete each quotation item with a description and valid amounts.');
                }
                $quantity = (float) $quantityRaw;
                $price = (float) $priceRaw;
                $discount = (float) $discountRaw;
                $tax = (float) $taxRaw;
                if (!is_finite($quantity) || !is_finite($price) || !is_finite($discount) || !is_finite($tax)
                    || $quantity <= 0 || $price < 0 || $discount < 0 || $discount > $quantity * $price || $tax < 0 || $tax > 100) {
                    throw new InvalidArgumentException('Check item quantities, rates, discounts and tax rates.');
                }
                $items[] = ['description' => $description, 'quantity' => $quantity, 'unit_price' => $price, 'discount' => $discount, 'tax_rate' => $tax];
            }
            if (!$items) {
                throw new InvalidArgumentException('Add at least one quotation item.');
            }
            $totals = invoiceTotals($items);
            if ($totals['total'] > 1.0e12) {
                throw new InvalidArgumentException('The quotation total is outside the supported range.');
            }

            $db->beginTransaction();
            $insert = $db->prepare("INSERT INTO quotations (business_id, customer_id, quotation_number, issue_date, expiry_date, currency, status, notes, terms, subtotal, discount, tax, total) VALUES (:business_id, :customer_id, :quotation_number, :issue_date, :expiry_date, :currency, 'Draft', :notes, :terms, :subtotal, :discount, :tax, :total)");
            $insert->execute([
                'business_id' => (int) $business['id'],
                'customer_id' => (int) $customerId,
                'quotation_number' => $number,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'currency' => (string) $business['currency'],
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                'terms' => trim((string) ($_POST['terms'] ?? '')) ?: null,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
            ]);
            $quoteId = (int) $db->lastInsertId();
            $itemInsert = $db->prepare('INSERT INTO quotation_items (quotation_id, description, quantity, unit_price, discount, tax_rate, line_total) VALUES (:quotation_id, :description, :quantity, :unit_price, :discount, :tax_rate, :line_total)');
            foreach ($items as $item) {
                $itemInsert->execute([
                    'quotation_id' => $quoteId,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'],
                    'tax_rate' => $item['tax_rate'],
                    'line_total' => calculateLineTotal($item['quantity'], $item['unit_price'], $item['discount'], $item['tax_rate']),
                ]);
            }
            $event = $db->prepare("INSERT INTO quotation_events (quotation_id, user_id, event_type) VALUES (:quotation_id, :user_id, 'created')");
            $event->execute(['quotation_id' => $quoteId, 'user_id' => (int) $_SESSION['user_id']]);
            $db->commit();
            setFlash('success', 'Quotation created.');
            redirect('quotes.php');
        }

        if (!$quote) {
            throw new InvalidArgumentException('Choose a quotation to update.');
        }
        if (in_array($action, ['send', 'accept', 'reject'], true)) {
            $nextStatus = transitionQuotationStatus($db, $quoteId, (int) $business['id'], $action);
            setFlash('success', 'Quotation marked ' . strtolower($nextStatus) . '.');
            redirect('quotes.php');
        }

        if ($action === 'convert') {
            assertPlanAllowsQuotations($db, (int) $_SESSION['user_id']);
            assertPlanLimit($db, (int) $_SESSION['user_id'], (int) $business['id'], 'invoices');
            $invoiceId = convertAcceptedQuotationToInvoice($db, $quoteId, (int) $business['id']);
            setFlash('success', 'Accepted quotation converted to a draft invoice.');
            redirect('invoice.php?id=' . $invoiceId);
        }
        throw new InvalidArgumentException('That quotation action is not available in its current status.');
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($exception instanceof InvalidArgumentException) {
            $errorMessage = $exception->getMessage();
        } else {
            error_log('Quotation action failed for business ' . (int) $business['id'] . ': ' . $exception->getMessage());
            $errorMessage = 'We could not save this quotation. Please try again.';
        }
    }
}

$customerStmt = $db->prepare('SELECT id, name, company FROM customers WHERE business_id = :business_id ORDER BY LOWER(name)');
$customerStmt->execute(['business_id' => (int) $business['id']]);
$customers = $customerStmt->fetchAll();
$quoteStmt = $db->prepare('SELECT q.*, c.name AS customer_name, c.company AS customer_company, t.id AS portal_token_id FROM quotations q LEFT JOIN customers c ON c.id = q.customer_id LEFT JOIN quotation_portal_tokens t ON t.quotation_id = q.id AND t.revoked_at IS NULL WHERE q.business_id = :business_id ORDER BY q.created_at DESC, q.id DESC');
$quoteStmt->execute(['business_id' => (int) $business['id']]);
$quotations = $quoteStmt->fetchAll();

$pageTitle = 'Quotations';
$siteTitle = 'Quotations | Invoices Generators';
$activeNav = 'quotes.php';
include __DIR__ . '/includes/header.php';
?>

<div class="app-page">
    <?php if ($successMessage && !$portalLink): ?><div class="notice notice-success"><?= e($successMessage); ?></div><?php endif; ?>
    <?php if ($errorMessage): ?><div class="notice notice-error"><?= e($errorMessage); ?></div><?php endif; ?>
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
    <?php endif; ?>

    <section class="card">
        <div class="card-head"><div><h3>Create quotation</h3><p class="muted-copy">Create a time-limited offer. Accepted quotations can be converted into draft invoices.</p></div></div>
        <?php if (!$planEntitlements['plan']['quote_access']): ?>
            <p class="notice">Quotation creation is included with Pro and Business. Existing quotations remain available below.</p>
            <a class="btn btn-primary" href="billing.php">View plans</a>
        <?php elseif (!$customers): ?>
            <p>Add a customer before creating a quotation. <a href="customers.php">Open customers</a></p>
        <?php else: ?>
        <form method="post" action="quotes.php">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div class="form-group"><label for="quote-customer">Customer</label><select id="quote-customer" name="customer_id" required><option value="">Choose customer</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id']; ?>"><?= e($customer['company'] ? $customer['name'] . ' · ' . $customer['company'] : $customer['name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="quote-number">Quotation number</label><input id="quote-number" name="quotation_number" maxlength="80" value="<?= e('QUO-' . gmdate('Ymd') . '-' . random_int(100, 999)); ?>" required></div>
                <div class="form-group"><label for="quote-issued">Issue date</label><input id="quote-issued" type="date" name="issue_date" value="<?= e($today); ?>" required></div>
                <div class="form-group"><label for="quote-expiry">Expiry date</label><input id="quote-expiry" type="date" name="expiry_date" value="<?= e(gmdate('Y-m-d', strtotime('+14 days'))); ?>" required></div>
            </div>
            <div class="table-wrap">
                <table class="invoice-table quote-item-table" data-quote-items>
                    <thead><tr><th>Description</th><th>Qty</th><th>Rate (<?= e($business['currency']); ?>)</th><th>Discount</th><th>Tax %</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        <tr class="item-row">
                            <td data-label="Description"><input name="item_description[]" maxlength="500" placeholder="Product or service" aria-label="Item description" required></td>
                            <td data-label="Quantity"><input type="number" name="item_quantity[]" min="0.01" step="0.01" value="1" aria-label="Quantity" required></td>
                            <td data-label="Rate (<?= e($business['currency']); ?>)"><input type="number" name="item_unit_price[]" min="0" step="0.01" value="0" aria-label="Rate" required></td>
                            <td data-label="Discount"><input type="number" name="item_discount[]" min="0" step="0.01" value="0" aria-label="Discount"></td>
                            <td data-label="Tax rate"><input type="number" name="item_tax_rate[]" min="0" max="100" step="0.01" value="0" aria-label="Tax rate"></td>
                            <td data-label="Actions"><button class="btn btn-secondary remove-row" type="button" aria-label="Remove item">×</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button class="btn btn-secondary" type="button" data-add-quote-item>Add item</button>
            <div class="form-grid">
                <div class="form-group"><label for="quote-notes">Notes (optional)</label><textarea id="quote-notes" name="notes" maxlength="2000"></textarea></div>
                <div class="form-group"><label for="quote-terms">Terms (optional)</label><textarea id="quote-terms" name="terms" maxlength="2000"></textarea></div>
            </div>
            <button class="btn btn-primary" type="submit">Save draft quotation</button>
        </form>
        <template id="quote-item-template"><tr class="item-row"><td data-label="Description"><input name="item_description[]" maxlength="500" placeholder="Product or service" aria-label="Item description" required></td><td data-label="Quantity"><input type="number" name="item_quantity[]" min="0.01" step="0.01" value="1" aria-label="Quantity" required></td><td data-label="Rate (<?= e($business['currency']); ?>)"><input type="number" name="item_unit_price[]" min="0" step="0.01" value="0" aria-label="Rate" required></td><td data-label="Discount"><input type="number" name="item_discount[]" min="0" step="0.01" value="0" aria-label="Discount"></td><td data-label="Tax rate"><input type="number" name="item_tax_rate[]" min="0" max="100" step="0.01" value="0" aria-label="Tax rate"></td><td data-label="Actions"><button class="btn btn-secondary remove-row" type="button" aria-label="Remove item">×</button></td></tr></template>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card-head"><h3>Quotation history</h3></div>
        <?php if (!$quotations): ?><p class="muted-copy">No quotations yet.</p><?php else: ?>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Number</th><th>Customer</th><th>Expiry</th><th>Status</th><th>Total</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($quotations as $quote): ?>
            <?php if ($quote['status'] === 'Sent' && $quote['expiry_date'] < $today): try { $quote['status'] = transitionQuotationStatus($db, (int) $quote['id'], (int) $business['id'], 'expire', $today); } catch (Throwable $exception) { error_log('Quotation expiry update failed: ' . $exception->getMessage()); } endif; ?>
            <tr><td><?= e($quote['quotation_number']); ?></td><td><?= e($quote['customer_name'] ?? 'Deleted customer'); ?></td><td><?= e($quote['expiry_date']); ?></td><td><?= e($quote['status']); ?></td><td><?= e(formatMoney((float) $quote['total'], $quote['currency'])); ?></td><td>
                <div class="quote-actions">
                    <a class="btn btn-secondary quote-pdf-action" href="quote_pdf.php?id=<?= (int) $quote['id']; ?>">PDF</a>
                    <?php if ($quote['status'] === 'Draft'): ?>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="quote_id" value="<?= (int) $quote['id']; ?>"><input type="hidden" name="action" value="send"><button class="btn btn-primary" type="submit">Mark sent</button></form>
                    <?php elseif ($quote['status'] === 'Sent'): ?>
                        <form method="post" action="portal_link.php"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="document_type" value="quotation"><input type="hidden" name="document_id" value="<?= (int) $quote['id']; ?>"><input type="hidden" name="action" value="create"><button class="btn btn-primary" type="submit"><?= $quote['portal_token_id'] ? 'Get new link' : 'Share with customer'; ?></button></form>
                    <?php elseif ($quote['status'] === 'Accepted' && empty($quote['converted_invoice_id'])): ?>
                        <?php if ($planEntitlements['plan']['quote_access']): ?>
                            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="quote_id" value="<?= (int) $quote['id']; ?>"><input type="hidden" name="action" value="convert"><button class="btn btn-primary" type="submit">Convert to invoice</button></form>
                        <?php else: ?>
                            <a class="btn btn-secondary" href="billing.php">Upgrade to convert</a>
                        <?php endif; ?>
                    <?php elseif (!empty($quote['converted_invoice_id'])): ?>
                        <a class="btn btn-primary" href="invoice.php?id=<?= (int) $quote['converted_invoice_id']; ?>">Open invoice</a>
                    <?php endif; ?>
                    <details class="quote-more-actions">
                        <summary class="btn btn-secondary">More</summary>
                        <div class="quote-more-menu">
                            <?php if ($quote['status'] !== 'Draft' && $quote['status'] !== 'Sent'): ?><form method="post" action="portal_link.php"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="document_type" value="quotation"><input type="hidden" name="document_id" value="<?= (int) $quote['id']; ?>"><input type="hidden" name="action" value="create"><button class="btn btn-secondary" type="submit"><?= $quote['portal_token_id'] ? 'Get new customer link' : 'Create customer link'; ?></button></form><?php endif; ?>
                            <?php if ($quote['portal_token_id']): ?><form method="post" action="portal_link.php"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="document_type" value="quotation"><input type="hidden" name="document_id" value="<?= (int) $quote['id']; ?>"><input type="hidden" name="action" value="revoke"><button class="btn btn-secondary" type="submit">Revoke customer link</button></form><?php endif; ?>
                            <?php if ($quote['status'] === 'Sent'): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="quote_id" value="<?= (int) $quote['id']; ?>"><input type="hidden" name="action" value="accept"><button class="btn btn-secondary" type="submit">Record acceptance</button></form><form method="post"><input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>"><input type="hidden" name="quote_id" value="<?= (int) $quote['id']; ?>"><input type="hidden" name="action" value="reject"><button class="btn btn-secondary" type="submit">Record rejection</button></form><?php endif; ?>
                        </div>
                    </details>
                </div>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </section>
</div>
<script>
document.addEventListener('click', (event) => {
    if (event.target.closest('[data-add-quote-item]')) {
        document.querySelector('[data-quote-items] tbody').append(document.querySelector('#quote-item-template').content.cloneNode(true));
    }
    const removeButton = event.target.closest('[data-quote-items] .remove-row');
    if (removeButton) {
        const rows = document.querySelectorAll('[data-quote-items] tbody tr');
        if (rows.length > 1) removeButton.closest('tr').remove();
    }
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
