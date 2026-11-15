<?php
require_once __DIR__ . '/app/init.php';
requireAuth($db);

$business = getCurrentBusiness($db);
if (!$business) {
    redirect('settings.php');
}
$planEntitlements = getPlanEntitlements($db, (int) $_SESSION['user_id']);

if (array_key_exists('proforma', $_GET)) {
    setFlash('error', 'Pro forma invoices are no longer available. Create a standard invoice instead.');
    redirect('invoice_create.php');
}

$templateOptions = [
    'classic' => [
        'name' => 'Classic',
        'description' => 'A timeless business layout',
    ],
    'modern' => [
        'name' => 'Modern',
        'description' => 'A bold, branded look',
    ],
    'minimal' => [
        'name' => 'Minimal',
        'description' => 'Clean and distraction-free',
    ],
    'executive' => [
        'name' => 'Executive',
        'description' => 'A polished, high-trust business style',
    ],
    'creative' => [
        'name' => 'Creative',
        'description' => 'A vivid layout for creative studios',
    ],
    'editorial' => [
        'name' => 'Editorial',
        'description' => 'A refined, typography-led design',
    ],
    'ledger' => [
        'name' => 'Ledger',
        'description' => 'A structured finance layout in deep green',
    ],
    'atelier' => [
        'name' => 'Atelier',
        'description' => 'A warm, expressive studio invoice',
    ],
    'horizon' => [
        'name' => 'Horizon',
        'description' => 'A crisp blue layout with an asymmetric grid',
    ],
    'studio' => [
        'name' => 'Studio',
        'description' => 'A bold, graphic layout for independent teams',
    ],
    'cobalt' => [
        'name' => 'Cobalt',
        'description' => 'A confident blue sidebar and crisp modern details',
    ],
    'terra' => [
        'name' => 'Terra',
        'description' => 'Warm clay accents and grounded service details',
    ],
    'noir' => [
        'name' => 'Noir',
        'description' => 'A dramatic charcoal header with a clean page',
    ],
    'botanical' => [
        'name' => 'Botanical',
        'description' => 'Soft greens and calm, rounded details',
    ],
    'aurora' => [
        'name' => 'Aurora',
        'description' => 'A luminous violet-blue look for digital work',
    ],
    'blueprint' => [
        'name' => 'Blueprint',
        'description' => 'Precise rules and measured technical spacing',
    ],
    'sunrise' => [
        'name' => 'Sunrise',
        'description' => 'Warm amber accents and clear totals',
    ],
    'monochrome' => [
        'name' => 'Monochrome',
        'description' => 'High-contrast black and white hierarchy',
    ],
    'coastal' => [
        'name' => 'Coastal',
        'description' => 'Sea-glass teal and airy spacing',
    ],
    'gilded' => [
        'name' => 'Gilded',
        'description' => 'Midnight ink with restrained gold details',
    ],
];
$requestedTemplate = $_GET['template'] ?? '';
$visualEditor = ($_GET['visual'] ?? '') === '1';
$editInvoiceId = (int) ($_GET['edit'] ?? $_POST['edit_invoice_id'] ?? 0);
$duplicateInvoiceId = (int) ($_GET['duplicate'] ?? 0);
$isEditing = $editInvoiceId > 0;
$sourceInvoice = null;
$customerName = '';
$invoiceNumber = 'INV-' . date('Ymd') . '-' . rand(100, 999);
$issueDate = date('Y-m-d');
$dueDate = date('Y-m-d', strtotime('+14 days'));
$selectedTemplate = is_string($requestedTemplate) && array_key_exists($requestedTemplate, $templateOptions)
    ? $requestedTemplate
    : 'classic';
$currency = strtoupper((string) $business['currency']);
$status = 'Draft';
$issuerName = (string) $business['name'];
$logoPath = resolveBusinessLogoPath($business['logo_path'] ?? null, (int) $business['id']) !== null
    ? $business['logo_path']
    : null;
$uploadedLogoPath = null;
$issuerEmail = (string) ($business['email'] ?? '');
$issuerPhone = (string) ($business['phone'] ?? '');
$issuerAddress = (string) ($business['address'] ?? '');
$issuerCity = (string) ($business['city'] ?? '');
$issuerCountry = (string) ($business['country'] ?? '');
$issuerTaxId = (string) ($business['tax_id'] ?? '');
$recipientCompany = '';
$recipientAddress = '';
$recipientEmail = '';
$recipientPhone = '';
$documentKind = 'invoice';
$documentLabel = 'PAYMENT REQUEST';
$documentTitle = 'Invoice';
$notes = '';
$terms = '';
$itemRows = [[
    'description' => '',
    'quantity' => '1',
    'unit_price' => '0',
    'discount' => '0',
    'tax_rate' => '0',
]];

if ($isEditing || $duplicateInvoiceId > 0) {
    $sourceInvoice = getInvoice($db, $isEditing ? $editInvoiceId : $duplicateInvoiceId, (int) $business['id']);
    if (!$sourceInvoice) {
        redirect('invoices.php');
    }
    if ($isEditing && $sourceInvoice['status'] !== 'Draft') {
        setFlash('error', 'Only draft invoices can be edited.');
        redirect('invoice.php?id=' . (int) $sourceInvoice['id']);
    }
    $sourceIsProforma = ($sourceInvoice['document_kind'] ?? 'invoice') === 'proforma';
    if ($isEditing && $sourceIsProforma) {
        setFlash('error', 'Existing pro forma documents are read-only. Duplicate one to create a standard invoice.');
        redirect('invoice.php?id=' . (int) $sourceInvoice['id']);
    }

    $customerStmt = $db->prepare('SELECT name FROM customers WHERE id = :customer_id AND business_id = :business_id');
    $customerStmt->execute(['customer_id' => $sourceInvoice['customer_id'], 'business_id' => $business['id']]);
    $customerName = (string) ($customerStmt->fetchColumn() ?: '');
    $invoiceNumber = $isEditing
        ? (string) $sourceInvoice['invoice_number']
        : 'INV-' . date('Ymd') . '-' . random_int(100, 999);
    $issueDate = $isEditing ? $sourceInvoice['issue_date'] : date('Y-m-d');
    $dueDate = $isEditing
        ? ($sourceInvoice['due_date'] ?? '')
        : (!empty($sourceInvoice['due_date']) ? date('Y-m-d', strtotime('+14 days')) : '');
    $selectedTemplate = array_key_exists($sourceInvoice['template'], $templateOptions) ? $sourceInvoice['template'] : 'classic';
    $currency = (string) ($sourceInvoice['currency'] ?? $business['currency']);
    $status = 'Draft';
    foreach ([
        'issuer_name' => 'issuerName',
        'issuer_email' => 'issuerEmail',
        'issuer_phone' => 'issuerPhone',
        'issuer_address' => 'issuerAddress',
        'issuer_city' => 'issuerCity',
        'issuer_country' => 'issuerCountry',
        'issuer_tax_id' => 'issuerTaxId',
        'recipient_company' => 'recipientCompany',
        'recipient_address' => 'recipientAddress',
        'recipient_email' => 'recipientEmail',
        'recipient_phone' => 'recipientPhone',
        'document_label' => 'documentLabel',
        'document_title' => 'documentTitle',
        'notes' => 'notes',
        'terms' => 'terms',
    ] as $column => $variable) {
        $$variable = (string) ($sourceInvoice[$column] ?? '');
    }
    if ($sourceIsProforma) {
        $documentLabel = 'PAYMENT REQUEST';
        $documentTitle = 'Invoice';
    }
    $logoPath = resolveBusinessLogoPath($sourceInvoice['logo_path'] ?? null, (int) $business['id']) !== null
        ? $sourceInvoice['logo_path']
        : $logoPath;

    $itemStmt = $db->prepare('SELECT description, quantity, unit_price, discount, tax_rate FROM invoice_items WHERE invoice_id = :invoice_id ORDER BY id ASC');
    $itemStmt->execute(['invoice_id' => (int) $sourceInvoice['id']]);
    $sourceItems = $itemStmt->fetchAll();
    if ($sourceItems) {
        $itemRows = array_map(static function (array $item): array {
            return [
                'description' => (string) $item['description'],
                'quantity' => (string) $item['quantity'],
                'unit_price' => (string) $item['unit_price'],
                'discount' => (string) $item['discount'],
                'tax_rate' => (string) $item['tax_rate'],
            ];
        }, $sourceItems);
    }
}

if (!$isEditing && $duplicateInvoiceId === 0
    && is_string($requestedTemplate)
    && array_key_exists($requestedTemplate, $templateOptions)
    && !in_array($requestedTemplate, $planEntitlements['plan']['templates'], true)) {
    setFlash('error', 'That invoice design is available on Pro and Business plans. Upgrade to use it.');
    redirect('billing.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();

        $customerName = trim((string) ($_POST['customer_name'] ?? ''));
        $editInvoiceId = (int) ($_POST['edit_invoice_id'] ?? $_GET['edit'] ?? 0);
        $isEditing = $editInvoiceId > 0;
        if ($isEditing && !$sourceInvoice) {
            $sourceInvoice = getInvoice($db, $editInvoiceId, (int) $business['id']);
        }
        $invoiceNumber = trim((string) ($_POST['invoice_number'] ?? $invoiceNumber));
        $issueDate = trim((string) ($_POST['issue_date'] ?? $issueDate));
        $dueDate = trim((string) ($_POST['due_date'] ?? $dueDate));
        $selectedTemplate = trim((string) ($_POST['template'] ?? 'classic'));
        $currency = strtoupper(trim((string) ($_POST['currency'] ?? $business['currency'])));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $terms = trim((string) ($_POST['terms'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? 'Draft'));
        $issuerName = trim((string) ($_POST['issuer_name'] ?? $business['name']));
        $issuerEmail = trim((string) ($_POST['issuer_email'] ?? $business['email'] ?? ''));
        $issuerPhone = trim((string) ($_POST['issuer_phone'] ?? $business['phone'] ?? ''));
        $issuerAddress = trim((string) ($_POST['issuer_address'] ?? $business['address'] ?? ''));
        $issuerCity = trim((string) ($_POST['issuer_city'] ?? $business['city'] ?? ''));
        $issuerCountry = trim((string) ($_POST['issuer_country'] ?? $business['country'] ?? ''));
        $issuerTaxId = trim((string) ($_POST['issuer_tax_id'] ?? $business['tax_id'] ?? ''));
        $recipientCompany = trim((string) ($_POST['recipient_company'] ?? ''));
        $recipientAddress = trim((string) ($_POST['recipient_address'] ?? ''));
        $recipientEmail = trim((string) ($_POST['recipient_email'] ?? ''));
        $recipientPhone = trim((string) ($_POST['recipient_phone'] ?? ''));
        $documentLabel = trim((string) ($_POST['document_label'] ?? 'PAYMENT REQUEST'));
        $documentTitle = trim((string) ($_POST['document_title'] ?? 'Invoice'));
        $documentKind = 'invoice';

        $descriptions = $_POST['item_description'] ?? [];
        $quantities = $_POST['item_quantity'] ?? [];
        $unitPrices = $_POST['item_unit_price'] ?? [];
        $discounts = $_POST['item_discount'] ?? [];
        $taxRates = $_POST['item_tax_rate'] ?? [];

        if ($customerName === '' || $invoiceNumber === '' || $issueDate === '' || $issuerName === '' || $documentLabel === '' || $documentTitle === '') {
            throw new InvalidArgumentException('Please provide the client, sender name, invoice heading, invoice number and issue date.');
        }

        if (!isValidInvoiceDate($issueDate) || ($dueDate !== '' && !isValidInvoiceDate($dueDate))) {
            throw new InvalidArgumentException('Enter valid issue and due dates.');
        }
        if ($dueDate !== '' && $dueDate < $issueDate) {
            throw new InvalidArgumentException('The due date cannot be before the issue date.');
        }
        if (strlen($invoiceNumber) > 80 || strlen($customerName) > 200) {
            throw new InvalidArgumentException('Invoice number must be 80 characters or fewer and client name 200 characters or fewer.');
        }
        if (!in_array($currency, ['USD', 'EUR', 'GHS', 'GBP', 'XAF'], true)) {
            throw new InvalidArgumentException('Choose a supported invoice currency.');
        }
        if ($isEditing && $status !== 'Draft') {
            throw new InvalidArgumentException('A draft must remain a draft while editing. Issue it from the invoice page.');
        }
        if (!$isEditing && !in_array($status, ['Draft', 'Issued'], true)) {
            throw new InvalidArgumentException('Choose Draft or Issued. Record payments from the invoice detail page.');
        }

        if (!is_array($descriptions) || !is_array($quantities) || !is_array($unitPrices) || !is_array($discounts) || !is_array($taxRates)) {
            throw new InvalidArgumentException('Please check the invoice items and try again.');
        }
        if (count($descriptions) > 100) {
            throw new InvalidArgumentException('An invoice can contain no more than 100 line items.');
        }

        if (!array_key_exists($selectedTemplate, $templateOptions)) {
            throw new InvalidArgumentException('Please choose a valid invoice design.');
        }
        $mayKeepCurrentTemplate = $isEditing
            && is_array($sourceInvoice)
            && (string) ($sourceInvoice['template'] ?? '') === $selectedTemplate;
        if (!$mayKeepCurrentTemplate) {
            assertPlanAllowsTemplate($db, (int) $_SESSION['user_id'], $selectedTemplate);
        }

        $items = [];
        $itemRows = [];
        foreach ($descriptions as $index => $description) {
            if (!array_key_exists($index, $quantities) || !array_key_exists($index, $unitPrices)
                || !array_key_exists($index, $discounts) || !array_key_exists($index, $taxRates)) {
                throw new InvalidArgumentException('Please check the invoice item fields and try again.');
            }
            $description = trim((string) $description);
            $quantity = trim((string) ($quantities[$index] ?? '1'));
            $unitPrice = trim((string) ($unitPrices[$index] ?? '0'));
            $discount = trim((string) ($discounts[$index] ?? '0'));
            $taxRate = trim((string) ($taxRates[$index] ?? '0'));

            $itemRows[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'tax_rate' => $taxRate,
            ];

            if ($description === '') {
                continue;
            }

            if (!is_numeric($quantity) || !is_numeric($unitPrice) || !is_numeric($discount) || !is_numeric($taxRate)) {
                throw new InvalidArgumentException('Invoice quantities, rates, discounts and tax rates must be valid numbers.');
            }
            $quantityValue = (float) $quantity;
            $unitPriceValue = (float) $unitPrice;
            $discountValue = (float) $discount;
            $taxRateValue = (float) $taxRate;
            if (!is_finite($quantityValue) || !is_finite($unitPriceValue) || !is_finite($discountValue) || !is_finite($taxRateValue)
                || $quantityValue <= 0 || $unitPriceValue < 0 || $discountValue < 0 || $taxRateValue < 0 || $taxRateValue > 100
                || $discountValue > $quantityValue * $unitPriceValue || strlen($description) > 500) {
                throw new InvalidArgumentException('Check each item: quantity must be positive, amounts cannot be negative, tax cannot exceed 100%, and discounts cannot exceed the item value.');
            }

            $items[] = [
                'description' => $description,
                'quantity' => $quantityValue,
                'unit_price' => $unitPriceValue,
                'discount' => $discountValue,
                'tax_rate' => $taxRateValue,
            ];
        }

        if (empty($itemRows)) {
            $itemRows[] = [
                'description' => '',
                'quantity' => '1',
                'unit_price' => '0',
                'discount' => '0',
                'tax_rate' => '0',
            ];
        }

        if (empty($items)) {
            throw new InvalidArgumentException('Please add at least one invoice item.');
        }

        $totals = invoiceTotals($items);
        if (!is_finite($totals['subtotal']) || !is_finite($totals['discount']) || !is_finite($totals['tax'])
            || !is_finite($totals['total']) || $totals['total'] > 1.0e12) {
            throw new InvalidArgumentException('Invoice totals are outside the supported range.');
        }

        $candidateLogoPath = ($isEditing || $duplicateInvoiceId > 0)
            ? (($sourceInvoice['logo_path'] ?? null) ?: ($business['logo_path'] ?? null))
            : ($business['logo_path'] ?? null);
        $logoPath = resolveBusinessLogoPath($candidateLogoPath, (int) $business['id']) !== null
            ? $candidateLogoPath
            : null;
        if (isset($_FILES['business_logo']) && (int) ($_FILES['business_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadedLogoPath = saveBusinessLogoUpload($_FILES['business_logo'], (int) $business['id']);
            $logoPath = $uploadedLogoPath;
        }

        $db->beginTransaction();
        if (!$isEditing) {
            if (databaseDriver($db) === 'mysql') {
                $lock = $db->prepare('SELECT id FROM subscriptions WHERE user_id = :user_id FOR UPDATE');
                $lock->execute(['user_id' => (int) $_SESSION['user_id']]);
                $lock->fetchColumn();
            }
            assertPlanLimit($db, (int) $_SESSION['user_id'], (int) $business['id'], 'invoices');
        }
        $existingInvoice = $isEditing
            ? getInvoice($db, $editInvoiceId, (int) $business['id'])
            : null;
        if ($isEditing && (!$existingInvoice || $existingInvoice['status'] !== 'Draft')) {
            throw new InvalidArgumentException('This invoice is no longer an editable draft.');
        }
        if ($isEditing) {
            $paymentCheck = $db->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = :invoice_id');
            $paymentCheck->execute(['invoice_id' => $editInvoiceId]);
            if ((int) $paymentCheck->fetchColumn() > 0) {
                throw new InvalidArgumentException('An invoice with recorded payments cannot be edited.');
            }
        }
        $numberCheck = $db->prepare('SELECT COUNT(*) FROM invoices WHERE business_id = :business_id AND LOWER(invoice_number) = LOWER(:invoice_number) AND id != :invoice_id');
        $numberCheck->execute([
            'business_id' => (int) $business['id'],
            'invoice_number' => $invoiceNumber,
            'invoice_id' => $editInvoiceId,
        ]);
        if ((int) $numberCheck->fetchColumn() > 0) {
            throw new InvalidArgumentException('That invoice number is already in use for this business.');
        }
        if ($uploadedLogoPath !== null) {
            $logoStmt = $db->prepare('UPDATE businesses SET logo_path = :logo_path WHERE id = :business_id');
            $logoStmt->execute([
                'logo_path' => $uploadedLogoPath,
                'business_id' => $business['id'],
            ]);
        }

        $customerStmt = $db->prepare('SELECT id FROM customers WHERE business_id = :business_id AND LOWER(name) = LOWER(:name) ORDER BY id ASC LIMIT 1');
        $customerStmt->execute([
            'business_id' => $business['id'],
            'name' => $customerName,
        ]);
        $customerId = $customerStmt->fetchColumn();

        if ($customerId === false) {
            assertPlanLimit($db, (int) $_SESSION['user_id'], (int) $business['id'], 'customers');
            $customerStmt = $db->prepare('INSERT INTO customers (business_id, name) VALUES (:business_id, :name)');
            $customerStmt->execute([
                'business_id' => $business['id'],
                'name' => $customerName,
            ]);
            $customerId = (int) $db->lastInsertId();
        } else {
            $customerId = (int) $customerId;
        }

        $invoiceValues = [
            'business_id' => (int) $business['id'],
            'customer_id' => $customerId,
            'invoice_number' => $invoiceNumber,
            'issue_date' => $issueDate,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'template' => $selectedTemplate,
            'currency' => $currency,
            'status' => $status,
            'issuer_name' => $issuerName,
            'issuer_email' => $issuerEmail,
            'issuer_phone' => $issuerPhone,
            'issuer_address' => $issuerAddress,
            'issuer_city' => $issuerCity,
            'issuer_country' => $issuerCountry,
            'issuer_tax_id' => $issuerTaxId,
            'recipient_company' => $recipientCompany !== '' ? $recipientCompany : null,
            'recipient_address' => $recipientAddress !== '' ? $recipientAddress : null,
            'recipient_email' => $recipientEmail !== '' ? $recipientEmail : null,
            'recipient_phone' => $recipientPhone !== '' ? $recipientPhone : null,
            'document_label' => $documentLabel,
            'document_title' => $documentTitle,
            'document_kind' => $documentKind,
            'logo_path' => $logoPath,
            'subtotal' => $totals['subtotal'],
            'discount' => $totals['discount'],
            'tax' => $totals['tax'],
            'total' => $totals['total'],
            'notes' => $notes,
            'terms' => $terms,
        ];
        if ($isEditing) {
            $updateValues = $invoiceValues;
            unset($updateValues['status']);
            $updateValues['id'] = $editInvoiceId;
            $stmt = $db->prepare("UPDATE invoices SET customer_id = :customer_id, invoice_number = :invoice_number, issue_date = :issue_date, due_date = :due_date, template = :template, currency = :currency, issuer_name = :issuer_name, issuer_email = :issuer_email, issuer_phone = :issuer_phone, issuer_address = :issuer_address, issuer_city = :issuer_city, issuer_country = :issuer_country, issuer_tax_id = :issuer_tax_id, recipient_company = :recipient_company, recipient_address = :recipient_address, recipient_email = :recipient_email, recipient_phone = :recipient_phone, document_label = :document_label, document_title = :document_title, document_kind = :document_kind, logo_path = :logo_path, subtotal = :subtotal, discount = :discount, tax = :tax, total = :total, notes = :notes, terms = :terms WHERE id = :id AND business_id = :business_id AND status = 'Draft'");
            $stmt->execute($updateValues);
            if ($stmt->rowCount() !== 1) {
                $draftCheck = $db->prepare("SELECT COUNT(*) FROM invoices WHERE id = :id AND business_id = :business_id AND status = 'Draft'");
                $draftCheck->execute(['id' => $editInvoiceId, 'business_id' => (int) $business['id']]);
                if ((int) $draftCheck->fetchColumn() !== 1) {
                    throw new InvalidArgumentException('This draft changed before it could be saved. Reload the page and try again.');
                }
            }
            $invoiceId = $editInvoiceId;
            $deleteItems = $db->prepare('DELETE FROM invoice_items WHERE invoice_id = :invoice_id');
            $deleteItems->execute(['invoice_id' => $invoiceId]);
            recordInvoiceEvent($db, $invoiceId, 'draft_edited', 'Draft', 'Draft');
        } else {
            $stmt = $db->prepare('INSERT INTO invoices (business_id, customer_id, invoice_number, issue_date, due_date, template, currency, status, issuer_name, issuer_email, issuer_phone, issuer_address, issuer_city, issuer_country, issuer_tax_id, recipient_company, recipient_address, recipient_email, recipient_phone, document_label, document_title, document_kind, logo_path, subtotal, discount, tax, total, notes, terms) VALUES (:business_id, :customer_id, :invoice_number, :issue_date, :due_date, :template, :currency, :status, :issuer_name, :issuer_email, :issuer_phone, :issuer_address, :issuer_city, :issuer_country, :issuer_tax_id, :recipient_company, :recipient_address, :recipient_email, :recipient_phone, :document_label, :document_title, :document_kind, :logo_path, :subtotal, :discount, :tax, :total, :notes, :terms)');
            $stmt->execute($invoiceValues);
            $invoiceId = (int) $db->lastInsertId();
            recordInvoiceEvent($db, $invoiceId, 'invoice_created', null, $status);
        }

        $itemStmt = $db->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, discount, tax_rate, line_total) VALUES (:invoice_id, :description, :quantity, :unit_price, :discount, :tax_rate, :line_total)');
        foreach ($items as $item) {
            $lineTotal = calculateLineTotal($item['quantity'], $item['unit_price'], $item['discount'], $item['tax_rate']);
            $itemStmt->execute([
                'invoice_id' => $invoiceId,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount' => $item['discount'],
                'tax_rate' => $item['tax_rate'],
                'line_total' => $lineTotal,
            ]);
        }

        $db->commit();
        setFlash('success', $isEditing ? 'Draft updated successfully.' : 'Invoice saved successfully.');
        redirect('invoice.php?id=' . $invoiceId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($uploadedLogoPath !== null) {
            $uploadedLogoAbsolutePath = resolveBusinessLogoPath($uploadedLogoPath, (int) $business['id']);
            if ($uploadedLogoAbsolutePath !== null && !unlink($uploadedLogoAbsolutePath)) {
                error_log('Unable to remove an uncommitted business logo upload: ' . $uploadedLogoPath);
            }
        }
        if ($e instanceof InvalidArgumentException) {
            $errorMessage = $e->getMessage();
        } else {
            error_log('Invoice save failed for business ' . (int) $business['id'] . ': ' . $e->getMessage());
            $errorMessage = 'We could not save this invoice. Please try again. If the problem continues, contact support.';
        }
    }
}

$pageTitle = $isEditing ? 'Edit draft' : 'Create invoice';
$siteTitle = ($isEditing ? 'Edit draft' : 'Create invoice') . ' | Invoices Generators';
$activeNav = 'templates.php';
$bodyClass = $visualEditor ? 'visual-invoice-editor' : '';
$formAction = 'invoice_create.php' . ($isEditing ? '?edit=' . $editInvoiceId : '') . ($visualEditor ? ($isEditing ? '&' : '?') . 'visual=1&template=' . rawurlencode($selectedTemplate) : '');
include __DIR__ . '/includes/header.php';

$successMessage = getFlash('success');
$redirectMessage = getFlash('error');
?>

<div class="app-page">
    <?php if (!empty($successMessage)): ?>
        <div class="notice notice-success"><?= e($successMessage); ?></div>
    <?php endif; ?>
    <?php if (!empty($redirectMessage)): ?>
        <div class="notice notice-error"><?= e($redirectMessage); ?></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="notice notice-error"><?= e($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($visualEditor): ?>
        <section class="visual-editor-card">
            <div class="visual-editor-toolbar">
                <div>
                    <span class="workspace-template-kicker"><?= $isEditing ? 'EDITING DRAFT' : 'EDITING TEMPLATE'; ?></span>
                    <h3><?= $isEditing ? 'Update your draft' : 'Your invoice, as it will look'; ?></h3>
                    <p>Click into any field on the invoice to make it yours.</p>
                </div>
                <a class="btn btn-secondary" href="templates.php">Change design</a>
            </div>

            <form method="post" enctype="multipart/form-data" action="<?= e($formAction); ?>" class="visual-invoice-form" data-invoice-submit data-loading-message="<?= $isEditing ? 'Saving your draft…' : 'Creating your invoice…'; ?>">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
                <?php if ($isEditing): ?><input type="hidden" name="edit_invoice_id" value="<?= (int) $editInvoiceId; ?>"><?php endif; ?>
                <input type="hidden" name="template" value="<?= e($selectedTemplate); ?>" data-visual-template-value>
                <input type="hidden" name="issuer_name" value="<?= e($issuerName); ?>" data-visual-sync-value="issuer_name">
                <input type="hidden" name="issuer_email" value="<?= e($issuerEmail); ?>" data-visual-sync-value="issuer_email">
                <div class="visual-editor-controls">
                    <label>Design
                        <select data-visual-template>
                            <?php foreach ($templateOptions as $templateKey => $templateOption): ?>
                                <?php if (!in_array($templateKey, $planEntitlements['plan']['templates'], true) && !($isEditing && $templateKey === $selectedTemplate)) { continue; } ?>
                                <option value="<?= e($templateKey); ?>" <?= $selectedTemplate === $templateKey ? 'selected' : ''; ?>><?= e($templateOption['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Currency
                        <select id="currency" name="currency" data-visual-currency>
                            <?php foreach (['USD', 'EUR', 'GHS', 'GBP', 'XAF'] as $currencyOption): ?>
                                <option value="<?= e($currencyOption); ?>" <?= $currency === $currencyOption ? 'selected' : ''; ?>><?= e($currencyOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="status" value="Draft">
                        <span class="visual-editor-status">Status: Draft</span>
                    <?php else: ?>
                        <label>Status
                            <select id="status" name="status">
                                <?php foreach (['Draft', 'Issued'] as $statusOption): ?>
                                    <option value="<?= e($statusOption); ?>" <?= $status === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                </div>

                <?php include __DIR__ . '/includes/invoice-ai-assist.php'; ?>

                <div class="visual-editor-stage">
                    <article class="invoice-document visual-invoice-document template-<?= e($selectedTemplate); ?>">
                        <header class="invoice-document-header">
                            <div class="invoice-issuer">
                                <span class="invoice-logo<?= $logoPath !== null ? ' has-image' : ''; ?>" data-issuer-mark>
                                    <span data-logo-fallback <?= $logoPath !== null ? 'hidden' : ''; ?>><?= e(strtoupper(substr(trim($issuerName), 0, 1)) ?: 'I'); ?></span>
                                    <img src="<?= $logoPath !== null ? 'logo.php?business=1' : ''; ?>" alt="" data-logo-preview <?= $logoPath === null ? 'hidden' : ''; ?>>
                                </span>
                                <span>
                                    <input class="visual-issuer-field visual-issuer-name" type="text" data-visual-sync="issuer_name" value="<?= e($issuerName); ?>" aria-label="Business or sender name" required>
                                    <input class="visual-issuer-field visual-issuer-email" type="email" data-visual-sync="issuer_email" value="<?= e($issuerEmail); ?>" placeholder="Business email" aria-label="Business email">
                                    <label class="visual-logo-upload no-print"><?= $logoPath !== null ? 'Change logo' : 'Upload logo'; ?>
                                        <input type="file" name="business_logo" accept="image/png,image/jpeg,image/webp" data-business-logo-input>
                                    </label>
                                    <span class="visual-logo-upload-error no-print" data-logo-upload-error role="status"></span>
                                </span>
                            </div>
                            <div class="invoice-heading">
                                <input class="visual-invoice-text visual-heading-label" type="text" name="document_label" data-visual-heading="label" value="<?= e($documentLabel); ?>" aria-label="Invoice heading label" required>
                                <input class="visual-invoice-title" type="text" name="document_title" data-visual-heading="title" value="<?= e($documentTitle); ?>" aria-label="Invoice title" required>
                                <input class="visual-invoice-number" type="text" name="invoice_number" value="<?= e($invoiceNumber); ?>" aria-label="Invoice number" required>
                            </div>
                        </header>

                        <div class="invoice-parties visual-invoice-parties">
                            <section>
                                <span class="invoice-label">FROM</span>
                                <input class="visual-invoice-text visual-sender-name" type="text" data-visual-sync="issuer_name" value="<?= e($issuerName); ?>" aria-label="Business or sender name" required>
                                <input class="visual-invoice-text" type="text" name="issuer_address" value="<?= e($issuerAddress); ?>" placeholder="Street address" aria-label="Business street address">
                                <div class="visual-issuer-location">
                                    <input class="visual-invoice-text" type="text" name="issuer_city" value="<?= e($issuerCity); ?>" placeholder="City" aria-label="Business city">
                                    <input class="visual-invoice-text" type="text" name="issuer_country" value="<?= e($issuerCountry); ?>" placeholder="Country" aria-label="Business country">
                                </div>
                                <input class="visual-invoice-text" type="email" data-visual-sync="issuer_email" value="<?= e($issuerEmail); ?>" placeholder="Business email" aria-label="Business email">
                                <input class="visual-invoice-text" type="text" name="issuer_phone" value="<?= e($issuerPhone); ?>" placeholder="Phone (optional)" aria-label="Business phone">
                                <input class="visual-invoice-text" type="text" name="issuer_tax_id" value="<?= e($issuerTaxId); ?>" placeholder="Tax ID (optional)" aria-label="Business tax ID">
                            </section>
                            <section>
                                <span class="invoice-label">BILL TO</span>
                                <input class="visual-invoice-text" id="customer_name" type="text" name="customer_name" value="<?= e($customerName); ?>" placeholder="Enter client name" aria-label="Client name" required>
                                <input class="visual-invoice-text" type="text" name="recipient_company" value="<?= e($recipientCompany); ?>" placeholder="Company (optional)" aria-label="Client company">
                                <input class="visual-invoice-text" type="text" name="recipient_address" value="<?= e($recipientAddress); ?>" placeholder="Address (optional)" aria-label="Client address">
                                <input class="visual-invoice-text" type="email" name="recipient_email" value="<?= e($recipientEmail); ?>" placeholder="Email (optional)" aria-label="Client email">
                                <input class="visual-invoice-text" type="text" name="recipient_phone" value="<?= e($recipientPhone); ?>" placeholder="Phone (optional)" aria-label="Client phone">
                            </section>
                            <section class="invoice-dates visual-invoice-dates">
                                <div>
                                    <span class="invoice-label">ISSUED</span>
                                    <input type="date" name="issue_date" value="<?= e($issueDate); ?>" aria-label="Issue date" required>
                                </div>
                                <div>
                                    <span class="invoice-label">DUE DATE</span>
                                    <input type="date" name="due_date" value="<?= e($dueDate); ?>" aria-label="Due date">
                                </div>
                                <div>
                                    <span class="invoice-label">STATUS</span>
                                    <strong class="invoice-status" data-visual-status><?= e($status); ?></strong>
                                </div>
                            </section>
                        </div>

                        <div class="invoice-items-wrap">
                            <table class="invoice-items visual-invoice-items" id="invoice-items-table">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>Qty</th>
                                        <th>Rate</th>
                                        <th>Tax</th>
                                        <th class="invoice-number-cell">Amount</th>
                                        <th class="no-print"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($itemRows as $itemRow): ?>
                                        <tr class="item-row">
                                            <td>
                                                <input class="visual-invoice-text" type="text" name="item_description[]" data-field="description" value="<?= e($itemRow['description']); ?>" placeholder="Describe the work" aria-label="Item description">
                                                <details class="visual-item-discount no-print">
                                                    <summary>Discount <span>Optional</span></summary>
                                                    <input type="number" min="0" step="0.01" name="item_discount[]" data-field="discount" value="<?= e($itemRow['discount']); ?>" aria-label="Item discount">
                                                </details>
                                            </td>
                                            <td><input type="number" min="1" step="0.01" name="item_quantity[]" data-field="quantity" value="<?= e($itemRow['quantity']); ?>" aria-label="Quantity"></td>
                                            <td><input type="number" min="0" step="0.01" name="item_unit_price[]" data-field="unit_price" value="<?= e($itemRow['unit_price']); ?>" aria-label="Rate"></td>
                                            <td><input type="number" min="0" step="0.01" name="item_tax_rate[]" data-field="tax_rate" value="<?= e($itemRow['tax_rate']); ?>" aria-label="Tax rate"></td>
                                            <td class="invoice-number-cell"><strong data-line-total-display>0.00</strong></td>
                                            <td class="no-print"><button class="visual-remove-item remove-row" type="button" aria-label="Remove item">×</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="visual-document-actions no-print">
                            <button class="btn btn-secondary" type="button" data-add-row>Add line item</button>
                        </div>

                        <template id="invoice-item-row-template">
                            <tr class="item-row">
                                <td>
                                    <input class="visual-invoice-text" type="text" name="item_description[]" data-field="description" placeholder="Describe the work" aria-label="Item description">
                                    <details class="visual-item-discount no-print">
                                        <summary>Discount <span>Optional</span></summary>
                                        <input type="number" min="0" step="0.01" name="item_discount[]" data-field="discount" value="0" aria-label="Item discount">
                                    </details>
                                </td>
                                <td><input type="number" min="1" step="0.01" name="item_quantity[]" data-field="quantity" value="1" aria-label="Quantity"></td>
                                <td><input type="number" min="0" step="0.01" name="item_unit_price[]" data-field="unit_price" value="0" aria-label="Rate"></td>
                                <td><input type="number" min="0" step="0.01" name="item_tax_rate[]" data-field="tax_rate" value="0" aria-label="Tax rate"></td>
                                <td class="invoice-number-cell"><strong data-line-total-display>0.00</strong></td>
                                <td class="no-print"><button class="visual-remove-item remove-row" type="button" aria-label="Remove item">×</button></td>
                            </tr>
                        </template>

                        <div class="invoice-summary-row visual-invoice-summary">
                            <div class="invoice-message">
                                <span class="invoice-label">A NOTE</span>
                                <textarea class="visual-invoice-text visual-invoice-note" name="notes" placeholder="Add a note for your client"><?= e($notes); ?></textarea>
                                <label class="visual-terms-label" for="visual-terms">PAYMENT TERMS</label>
                                <textarea class="visual-invoice-text visual-invoice-note" id="visual-terms" name="terms" placeholder="Add your payment terms"><?= e($terms); ?></textarea>
                            </div>
                            <div class="invoice-totals">
                                <div><span>Subtotal</span><strong id="subtotal-value">0.00</strong></div>
                                <div><span>Discount</span><strong id="discount-value">0.00</strong></div>
                                <div><span>Tax</span><strong id="tax-value">0.00</strong></div>
                                <div class="invoice-grand-total"><span>Total due</span><strong id="total-value">0.00</strong></div>
                            </div>
                        </div>

                        <footer class="invoice-document-footer">
                            <span><?= e($business['name']); ?></span>
                            <span><?= e($business['email'] ?: $business['phone'] ?: ''); ?></span>
                        </footer>
                    </article>
                </div>

                <div class="visual-editor-submit">
                    <a class="btn btn-secondary" href="templates.php">Back to templates</a>
                    <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save draft' : 'Create invoice'; ?></button>
                </div>
            </form>
        </section>
    <?php else: ?>
    <section class="card editor-card">
        <div class="card-head">
            <div>
                <h3><?= $isEditing ? 'Edit draft' : 'Create an invoice'; ?></h3>
                <p class="editor-intro">Starting with the <?= e($templateOptions[$selectedTemplate]['name']); ?> design. Enter your details below, or <a href="templates.php">choose another template</a>.</p>
            </div>
        </div>

        <form method="post" action="<?= e($formAction); ?>" data-invoice-submit data-loading-message="<?= $isEditing ? 'Saving your draft…' : 'Creating your invoice…'; ?>">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()); ?>">
            <input type="hidden" name="document_label" value="<?= e($documentLabel); ?>">
            <input type="hidden" name="document_title" value="<?= e($documentTitle); ?>">
            <?php if ($isEditing): ?><input type="hidden" name="edit_invoice_id" value="<?= (int) $editInvoiceId; ?>"><?php endif; ?>
            <?php include __DIR__ . '/includes/invoice-ai-assist.php'; ?>

            <section class="editor-step" aria-labelledby="client-step-title">
                <div class="editor-step-heading">
                    <span class="editor-step-number">1</span>
                    <div>
                        <h4 id="client-step-title">Who is this invoice for?</h4>
                        <p>Enter the client name and confirm the invoice number.</p>
                    </div>
                </div>
                <div class="form-grid editor-primary-fields">
                <div class="form-group">
                    <label for="customer_name">Client name</label>
                    <input id="customer_name" type="text" name="customer_name" value="<?= e($customerName); ?>" placeholder="Enter client or company name" autocomplete="organization" required>
                </div>
                <div class="form-group">
                    <label for="invoice_number">Invoice number</label>
                    <input id="invoice_number" type="text" name="invoice_number" value="<?= e($invoiceNumber); ?>" required>
                </div>
                </div>
            </section>

            <details class="editor-options">
                <summary>Dates, currency and design <span>Optional settings</span></summary>
                <div class="editor-options-content">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="issue_date">Issue date</label>
                            <input id="issue_date" type="date" name="issue_date" value="<?= e($issueDate); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="due_date">Due date</label>
                            <input id="due_date" type="date" name="due_date" value="<?= e($dueDate); ?>">
                        </div>
                        <div class="form-group">
                            <label for="currency">Currency</label>
                            <select id="currency" name="currency">
                                <?php foreach (['USD', 'EUR', 'GHS', 'GBP', 'XAF'] as $currencyOption): ?>
                                    <option value="<?= e($currencyOption); ?>" <?= $currency === $currencyOption ? 'selected' : ''; ?>><?= e($currencyOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isEditing): ?>
                            <input type="hidden" name="status" value="Draft">
                            <div class="form-group"><label>Status</label><p>Draft (issue after saving)</p></div>
                        <?php else: ?>
                            <div class="form-group">
                                <label for="status">Status</label>
                                <select id="status" name="status">
                                    <?php foreach (['Draft', 'Issued'] as $statusOption): ?>
                                        <option value="<?= e($statusOption); ?>" <?= $status === $statusOption ? 'selected' : ''; ?>><?= e($statusOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>

                    <fieldset class="template-picker">
                        <legend>Invoice design</legend>
                        <p class="template-picker-hint">Choose how the finished invoice will look.</p>
                        <div class="template-choice-grid">
                            <?php foreach ($templateOptions as $templateKey => $templateOption): ?>
                                <?php if (!in_array($templateKey, $planEntitlements['plan']['templates'], true) && !($isEditing && $templateKey === $selectedTemplate)) { continue; } ?>
                                <label class="template-choice">
                                    <input type="radio" name="template" value="<?= e($templateKey); ?>" <?= $selectedTemplate === $templateKey ? 'checked' : ''; ?>>
                                    <span class="template-choice-preview template-choice-<?= e($templateKey); ?>" aria-hidden="true">
                                        <span class="choice-preview-header"><i></i><b><?= e($templateOption['name']); ?></b><strong>INVOICE</strong></span>
                                        <span class="choice-preview-meta"><i></i><i></i></span>
                                        <span class="choice-preview-table"><i></i><i></i><i></i></span>
                                        <span class="choice-preview-total"></span>
                                    </span>
                                    <span class="template-choice-name"><?= e($templateOption['name']); ?><small><?= e($templateOption['description']); ?></small></span>
                                    <span class="template-choice-check" aria-hidden="true"></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                </div>
            </details>

            <section class="editor-step editor-items-step" aria-labelledby="items-step-title">
                <div class="editor-step-heading">
                    <span class="editor-step-number">2</span>
                    <div>
                        <h4 id="items-step-title">What are you charging for?</h4>
                        <p>Enter a description and rate for each item.</p>
                    </div>
                </div>
                <div class="editor-items-toolbar">
                    <span>Invoice items</span>
                    <button type="button" class="btn btn-secondary" data-add-row>Add item</button>
                </div>
                <div class="table-wrap">
                <table class="invoice-table" id="invoice-items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Amount</th>
                            <th><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itemRows as $itemRow): ?>
                        <tr class="item-row">
                            <td class="invoice-item-description">
                                <input type="text" name="item_description[]" data-field="description" value="<?= e($itemRow['description']); ?>" placeholder="Describe the product or service" aria-label="Item description">
                                <details class="item-adjustments">
                                    <summary>Discount or tax <span>Optional</span></summary>
                                    <div class="item-adjustments-fields">
                                        <label>Discount<input type="number" min="0" step="0.01" name="item_discount[]" data-field="discount" value="<?= e($itemRow['discount']); ?>"></label>
                                        <label>Tax rate (%)<input type="number" min="0" step="0.01" name="item_tax_rate[]" data-field="tax_rate" value="<?= e($itemRow['tax_rate']); ?>"></label>
                                    </div>
                                </details>
                            </td>
                            <td><input type="number" min="1" step="0.01" name="item_quantity[]" data-field="quantity" value="<?= e($itemRow['quantity']); ?>" aria-label="Quantity"></td>
                            <td><input type="number" min="0" step="0.01" name="item_unit_price[]" data-field="unit_price" value="<?= e($itemRow['unit_price']); ?>" aria-label="Rate"></td>
                            <td><input type="number" step="0.01" name="item_line_total[]" data-field="line_total" value="0" readonly aria-label="Line amount"></td>
                            <td><button type="button" class="btn btn-secondary remove-row no-print" aria-label="Remove item">×</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <template id="invoice-item-row-template">
                    <tr class="item-row">
                        <td class="invoice-item-description">
                            <input type="text" name="item_description[]" data-field="description" placeholder="Describe the product or service" aria-label="Item description">
                            <details class="item-adjustments">
                                <summary>Discount or tax <span>Optional</span></summary>
                                <div class="item-adjustments-fields">
                                    <label>Discount<input type="number" min="0" step="0.01" name="item_discount[]" data-field="discount" value="0"></label>
                                    <label>Tax rate (%)<input type="number" min="0" step="0.01" name="item_tax_rate[]" data-field="tax_rate" value="0"></label>
                                </div>
                            </details>
                        </td>
                        <td><input type="number" min="1" step="0.01" name="item_quantity[]" data-field="quantity" value="1" aria-label="Quantity"></td>
                        <td><input type="number" min="0" step="0.01" name="item_unit_price[]" data-field="unit_price" value="0" aria-label="Rate"></td>
                        <td><input type="number" step="0.01" name="item_line_total[]" data-field="line_total" value="0" readonly aria-label="Line amount"></td>
                        <td><button type="button" class="btn btn-secondary remove-row no-print" aria-label="Remove item">×</button></td>
                    </tr>
                </template>
            </section>

            <div class="amount-box" style="margin-top: 18px;">
                <div class="total-sheet">
                    <details class="total-breakdown">
                        <summary>Show subtotal, discounts and tax</summary>
                        <div class="total-line"><span>Subtotal</span><strong id="subtotal-value">$0.00</strong></div>
                        <div class="total-line"><span>Discount</span><strong id="discount-value">$0.00</strong></div>
                        <div class="total-line"><span>Tax</span><strong id="tax-value">$0.00</strong></div>
                    </details>
                    <div class="total-line total-line-grand"><span>Total due</span><strong id="total-value">$0.00</strong></div>
                </div>
            </div>

            <details class="editor-options editor-message-options">
                <summary>Note or payment terms <span>Optional</span></summary>
                <div class="editor-options-content">
                    <div class="form-group">
                        <label for="notes">Note for your client</label>
                        <textarea id="notes" name="notes" placeholder="Thank you for your partnership."><?= e($notes); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="terms">Payment terms</label>
                        <textarea id="terms" name="terms" placeholder="Payment due within 14 days."><?= e($terms); ?></textarea>
                    </div>
                </div>
            </details>

            <div class="inline-actions">
                <button class="btn btn-primary" type="submit"><?= $isEditing ? 'Save draft' : 'Create invoice'; ?></button>
                <a class="btn btn-secondary" href="invoices.php">Cancel</a>
            </div>
        </form>
    </section>
    <?php endif; ?>
    <div class="invoice-loading-screen" data-invoice-loading-screen hidden>
        <div class="invoice-loading-card" role="status" aria-live="polite">
            <span class="invoice-loading-spinner" aria-hidden="true"></span>
            <strong data-invoice-loading-message>Saving your invoice…</strong>
            <span>Please wait while we save your details.</span>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
