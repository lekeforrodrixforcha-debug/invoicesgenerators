<?php

function renderInvoicePdfHtml(array $invoice, array $items, ?array $customer, array $business, array $paymentSummary, bool $forBrowser = false): string
{
    $templates = [
        'classic' => ['accent' => '#6956d5', 'soft' => '#f2efff', 'ink' => '#252331'],
        'modern' => ['accent' => '#5542bf', 'soft' => '#f3f0ff', 'ink' => '#252331'],
        'minimal' => ['accent' => '#34313a', 'soft' => '#f1efeb', 'ink' => '#292633'],
        'executive' => ['accent' => '#5141ae', 'soft' => '#eeebf7', 'ink' => '#29243f'],
        'creative' => ['accent' => '#6956d5', 'soft' => '#f2efff', 'ink' => '#252331'],
        'editorial' => ['accent' => '#5542bf', 'soft' => '#f5f0e7', 'ink' => '#352f28'],
        'ledger' => ['accent' => '#1f6b54', 'soft' => '#eaf3ef', 'ink' => '#203a31'],
        'atelier' => ['accent' => '#b65c3b', 'soft' => '#fbefe9', 'ink' => '#422c25'],
        'horizon' => ['accent' => '#2874a6', 'soft' => '#eaf3f8', 'ink' => '#1c3e53'],
        'studio' => ['accent' => '#a33e68', 'soft' => '#f1eaf0', 'ink' => '#382437'],
        'cobalt' => ['accent' => '#2457c5', 'soft' => '#edf3ff', 'ink' => '#1c315d'],
        'terra' => ['accent' => '#a64f35', 'soft' => '#f9eee7', 'ink' => '#513126'],
        'noir' => ['accent' => '#32343a', 'soft' => '#ededee', 'ink' => '#25262a'],
        'botanical' => ['accent' => '#397252', 'soft' => '#eff7f0', 'ink' => '#263d2e'],
        'aurora' => ['accent' => '#6247c5', 'soft' => '#f0edff', 'ink' => '#30265f'],
        'blueprint' => ['accent' => '#17668c', 'soft' => '#f0f7fa', 'ink' => '#193746'],
        'sunrise' => ['accent' => '#bd6a16', 'soft' => '#fff8e9', 'ink' => '#4d371d'],
        'monochrome' => ['accent' => '#111111', 'soft' => '#eeeeee', 'ink' => '#111111'],
        'coastal' => ['accent' => '#177d83', 'soft' => '#eaf6f5', 'ink' => '#1d4142'],
        'gilded' => ['accent' => '#a8833d', 'soft' => '#f6f1e4', 'ink' => '#282b32'],
    ];
    $template = (string) ($invoice['template'] ?? 'classic');
    if (!array_key_exists($template, $templates)) {
        $template = 'classic';
    }
    $theme = $templates[$template] ?? $templates['classic'];
    $layoutStyles = [
        'modern' => '.document.template-modern{border-top:7px solid #5542bf}.template-modern .header td{padding:16px 18px;background:#f3f0ff;border-bottom:0}.template-modern .header h1{color:#5542bf}.template-modern .items th{border:0;background:#f2efff;color:#5d4cb9}.template-modern .totals .grand td{background:#5542bf;color:#fff}.template-modern .parties .dates{border:1px solid #eeebf8;background:#fbfaff}',
        'minimal' => '.document.template-minimal{border:1px solid #e6e3df;box-shadow:none}.template-minimal .header td{border-bottom-color:#dedbd5}.template-minimal .items th{border-top:1px solid #dcd8d2;border-bottom:1px solid #dcd8d2;background:transparent;color:#77716c}.template-minimal .totals .grand td{border-top:1px solid #dcd8d2;border-bottom:1px solid #dcd8d2;background:transparent;color:#292633}',
        'executive' => '.document.template-executive{border-top:6px solid #342b69}.template-executive .header td{padding:16px 18px;background:#342b69;border-color:#342b69;color:#fff}.template-executive .header h1,.template-executive .header .issuer-name{color:#fff}.template-executive .header .issuer-contact,.template-executive .header .kicker,.template-executive .header .invoice-number{color:#d7d0f4}.template-executive .items th,.template-executive .totals .grand td{border:0;background:#342b69;color:#fff}.template-executive .parties .dates{background:#f5f3fb}',
        'creative' => '.document.template-creative{border-left:7px solid #6956d5}.template-creative .header td{border-bottom:2px solid #ded8fb}.template-creative .header h1{color:#6956d5}.template-creative .items th{border:0;background:#eeeaff;color:#5141ae}.template-creative .totals .grand td{background:#eeeaff;color:#5141ae}.template-creative .items tbody tr:nth-child(even){background:#faf9ff}',
        'editorial' => '.document.template-editorial{border:1px solid #e9e3da;background:#fffefa}.template-editorial .header td{padding-bottom:24px;border-bottom-color:#d8cdbd}.template-editorial .header h1{color:#342b69}.template-editorial .header .kicker,.template-editorial .parties .label{color:#82745f}.template-editorial .items th{border-top:1px solid #d8cdbd;border-bottom:1px solid #d8cdbd;background:transparent;color:#82745f}.template-editorial .items td{border-bottom-color:#eee8df}.template-editorial .totals .grand td{border-top:1px solid #d8cdbd;border-bottom:1px solid #d8cdbd;background:transparent;color:#342b69}',
        'ledger' => '.document.template-ledger{border-top:8px solid #164d3d}.template-ledger .header td{padding:17px 18px;background:#164d3d;border:0;color:#fff}.template-ledger .header h1,.template-ledger .header .issuer-name{color:#fff}.template-ledger .header .issuer-contact,.template-ledger .header .kicker,.template-ledger .header .invoice-number{color:#c5e2d4}.template-ledger .parties td{padding:10px;background:#f7fbf8;border:1px solid #e1eee7}.template-ledger .items th{background:#eaf4ee;color:#1f6b54}.template-ledger .totals .grand td{background:#1f6b54;color:#fff}',
        'atelier' => '.document.template-atelier{border:1px solid #f0dfd4;background:#fffaf5}.template-atelier .header td{padding:18px;background:#f6e8dd;border:0}.template-atelier .header .kicker{color:#9c573f}.template-atelier .items th{border-top:1px solid #e9d8ca;border-bottom:1px solid #e9d8ca;background:transparent;color:#9c573f}.template-atelier .items td{border-bottom-color:#f0e5dc}.template-atelier .totals .grand td{background:#b65c3b;color:#fff}',
        'horizon' => '.document.template-horizon{border:0;border-left:9px solid #2874a6}.template-horizon .header td{border-bottom:2px solid #d6e8f3}.template-horizon .header h1{color:#1c4c6c}.template-horizon .header .kicker{color:#2874a6}.template-horizon .items th{border:0;background:#2874a6;color:#fff}.template-horizon .totals .grand td{background:#e7f2f8;color:#1c4c6c}',
        'studio' => '.document.template-studio{border:1px solid #ead9e1}.template-studio .header td{padding:17px 18px;background:#382437;border:0;color:#fff}.template-studio .header h1,.template-studio .header .issuer-name{color:#fff}.template-studio .header .issuer-contact,.template-studio .header .kicker,.template-studio .header .invoice-number{color:#e4cbd7}.template-studio .items th{background:#f1eaf0;color:#a33e68}.template-studio .totals .grand td{background:#a33e68;color:#fff}',
        'cobalt' => '.document.template-cobalt{border-top:0;border-left:7px solid #2457c5;padding-left:12px}.template-cobalt .header td{background:#edf3ff}.template-cobalt .items th,.template-cobalt .totals .grand td{background:#2457c5;color:#fff}',
        'terra' => '.document.template-terra{border-top:6px solid #a64f35;background:#fffdf9}.template-terra .header td{background:#f9eee7;border:0}.template-terra .header h1{color:#a64f35}.template-terra .items th{border-top:1px solid #e8cdbf;border-bottom:1px solid #e8cdbf;background:#fffaf5;color:#8d452f}.template-terra .totals .grand td{background:#f9eee7;color:#7d3c2b}',
        'noir' => '.document.template-noir{border:1px solid #d7d9de;box-shadow:none}.template-noir .header td{padding:17px 18px;background:#32343a;color:#fff;border-color:#32343a}.template-noir .header h1,.template-noir .header .issuer-name{color:#fff}.template-noir .header .issuer-contact,.template-noir .header .invoice-number,.template-noir .header .kicker{color:#d6d7dc}.template-noir .items th,.template-noir .totals .grand td{background:#32343a;color:#fff}',
        'botanical' => '.document.template-botanical{border:1px solid #d8e6dc;background:#fcfefb}.template-botanical .header td{padding:16px;background:#eff7f0;border:1px solid #e2eee5}.template-botanical .header h1{color:#397252}.template-botanical .parties .dates{background:#eff7f0;border:1px solid #d8e6dc}.template-botanical .items th{background:#e8f2e9;color:#397252}.template-botanical .totals .grand td{background:#397252;color:#fff}',
        'aurora' => '.document.template-aurora{border-top:7px solid #6247c5}.template-aurora .header td{background:#f0edff;border-color:#d9d2fa}.template-aurora .header h1{color:#6247c5}.template-aurora .items th{background:#eeebff;color:#6247c5}.template-aurora .totals .grand td{background:#6247c5;color:#fff}',
        'blueprint' => '.document.template-blueprint{border:2px solid #17668c;box-shadow:none}.template-blueprint .header td,.template-blueprint .parties td{border-color:#b9d1df}.template-blueprint .header td{background:#f0f7fa}.template-blueprint .header h1,.template-blueprint .header .kicker,.template-blueprint .parties .label{color:#17668c}.template-blueprint .items th{background:#17668c;color:#fff}.template-blueprint .totals .grand td{background:#f0f7fa;color:#17668c}',
        'sunrise' => '.document.template-sunrise{border:1px solid #eadcc7}.template-sunrise .header td{background:#fff8e9;border-bottom:3px solid #e9b86c}.template-sunrise .header h1{color:#bd6a16}.template-sunrise .items th{border-color:#f0dfc0;background:#fff8e9;color:#9c5e1c}.template-sunrise .totals .grand td{background:#f8ebd1;color:#8d5013}',
        'monochrome' => '.document.template-monochrome{border:2px solid #171717;box-shadow:none}.template-monochrome .header td{border-bottom:2px solid #111}.template-monochrome .header .kicker{color:#111}.template-monochrome .items th,.template-monochrome .totals .grand td{background:#111;color:#fff}',
        'coastal' => '.document.template-coastal{border-top:0;border-bottom:7px solid #177d83}.template-coastal .header td{background:#eaf6f5;border:0}.template-coastal .items th{background:#dff0ee;color:#146a70}.template-coastal .totals .grand td{background:#177d83;color:#fff}',
        'gilded' => '.document.template-gilded{border-top:7px solid #202a3b;border-right:1px solid #d9c89f;border-bottom:1px solid #d9c89f;border-left:1px solid #d9c89f;background:#fffefa}.template-gilded .header td{background:#202a3b;border-color:#c8ae72;color:#fff}.template-gilded .header h1,.template-gilded .header .issuer-name{color:#fff}.template-gilded .header .issuer-contact,.template-gilded .header .invoice-number,.template-gilded .header .kicker{color:#e4d7b8}.template-gilded .items th{background:#f6f1e4;color:#7c612c}.template-gilded .totals .grand td{background:#202a3b;color:#fff}',
    ];
    $templateLayoutStyles = $layoutStyles[$template] ?? '';
    $browserStyles = $forBrowser ? '
        body { padding: 24px; background: #f4f3f8; font-size: 14px; }
        .document { max-width: 980px; margin: 0 auto; padding: 30px 34px 22px; background: #fff; border-radius: 12px; box-shadow: 0 18px 48px rgba(37, 35, 49, .10); }
        .issuer-name { font-size: 16px; }
        .issuer-contact, .invoice-number, .party-line, .date-value { font-size: 12px; }
        .kicker, .label { font-size: 10px; }
        h1 { font-size: 32px; }
        .party-name { font-size: 14px; }
        .items-scroll { overflow-x: auto; }
        .items-scroll .items { min-width: 560px; }
        .items th { font-size: 11px; }
        .items td, .totals tr td, .message { font-size: 12px; }
        .totals .grand td { font-size: 14px; }
        .footer { font-size: 10px; }
        .portal-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; max-width: 980px; margin: 0 auto 16px; }
        .portal-actions .btn { display: inline-flex; min-height: 44px; align-items: center; padding: 0 18px; border: 0; border-radius: 8px; background: #6956d5; color: #fff; font: 600 14px DejaVu Sans, sans-serif; text-decoration: none; cursor: pointer; }
        .portal-actions .btn-secondary { background: #fff; color: #413b5d; border: 1px solid #ddd9e8; }
        .portal-actions form { display: flex; flex-wrap: wrap; gap: 12px; }
        .portal-error { flex-basis: 100%; margin: 0; color: #a33142; font-size: 13px; }
        .portal-note { flex-basis: 100%; margin: 0; color: #676573; font-size: 13px; }
        @media (max-width: 640px) {
            body { padding: 12px; }
            .document { padding: 20px 16px; }
            .header, .header tbody, .header tr, .header td { display: block; width: 100%; text-align: left; }
            .header td { padding-bottom: 12px; }
            .heading { padding-top: 12px; }
            .parties, .parties tbody, .parties tr, .parties td { display: block; width: 100% !important; }
            .parties td { margin-bottom: 16px; }
            .parties .dates { margin-bottom: 0; }
            h1 { font-size: 27px; }
            .portal-actions .btn { flex: 1 1 100%; justify-content: center; }
        }
    ' : '';
    $viewportMeta = $forBrowser ? '<meta name="viewport" content="width=device-width, initial-scale=1">' : '';
    $robotsMeta = $forBrowser ? '<meta name="robots" content="noindex, nofollow">' : '';
    $currency = (string) ($invoice['currency'] ?? $business['currency'] ?? 'USD');
    $issuerName = (string) (($invoice['issuer_name'] ?? '') ?: $business['name']);
    $issuerEmail = (string) ($invoice['issuer_email'] ?? $business['email'] ?? '');
    $issuerPhone = (string) ($invoice['issuer_phone'] ?? $business['phone'] ?? '');
    $issuerAddress = (string) ($invoice['issuer_address'] ?? $business['address'] ?? '');
    $issuerCity = (string) ($invoice['issuer_city'] ?? $business['city'] ?? '');
    $issuerCountry = (string) ($invoice['issuer_country'] ?? $business['country'] ?? '');
    $issuerTaxId = (string) ($invoice['issuer_tax_id'] ?? $business['tax_id'] ?? '');
    $recipientName = (string) ($customer['name'] ?? 'Client');
    $logoPath = is_string($invoice['logo_path'] ?? null) && $invoice['logo_path'] !== ''
        ? $invoice['logo_path']
        : ($business['logo_path'] ?? null);
    $logoAbsolutePath = resolveBusinessLogoPath(is_string($logoPath) ? $logoPath : null, (int) $business['id']);
    $logoMarkup = '';
    if ($logoAbsolutePath !== null) {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($logoAbsolutePath);
        if (in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            $logoData = file_get_contents($logoAbsolutePath);
            if ($logoData === false) {
                throw new RuntimeException('Unable to read the invoice logo for PDF rendering.');
            }
            $logoMarkup = '<img class="logo" src="data:' . e($mimeType) . ';base64,' . base64_encode($logoData) . '" alt="">';
        }
    }

    $businessInitial = strtoupper(substr(trim($issuerName), 0, 1)) ?: 'I';
    $issueDate = date('M j, Y', strtotime((string) $invoice['issue_date']));
    $dueDate = !empty($invoice['due_date']) ? date('M j, Y', strtotime((string) $invoice['due_date'])) : 'Upon receipt';
    $invoiceStatus = (string) $paymentSummary['status'];
    $subtotal = (float) $invoice['subtotal'];
    $discount = (float) $invoice['discount'];
    $tax = (float) $invoice['tax'];
    $total = (float) $invoice['total'];
    $amountPaid = (int) $paymentSummary['amount_paid_cents'] / 100;
    $balance = (int) $paymentSummary['balance_cents'] / 100;
    $descriptionLines = [];
    foreach ($items as $item) {
        $descriptionLines[] = '<tr>'
            . '<td class="description"><strong>' . e((string) $item['description']) . '</strong></td>'
            . '<td class="number">' . e(rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.')) . '</td>'
            . '<td class="number">' . e(formatMoney((float) $item['unit_price'], $currency)) . '</td>'
            . '<td class="number">' . e(number_format((float) $item['tax_rate'], 2, '.', '')) . '%</td>'
            . '<td class="number">' . e(formatMoney((float) $item['line_total'], $currency)) . '</td>'
            . '</tr>';
    }
    $issuerLines = array_filter([
        $issuerAddress,
        implode(', ', array_filter([$issuerCity, $issuerCountry])),
        $issuerEmail,
        $issuerPhone,
        $issuerTaxId !== '' ? 'Tax ID ' . $issuerTaxId : '',
    ]);
    $recipientLines = array_filter([
        $invoice['recipient_company'] ?? ($customer['company'] ?? ''),
        $invoice['recipient_address'] ?? ($customer['address'] ?? ''),
        $invoice['recipient_email'] ?? ($customer['email'] ?? ''),
        $invoice['recipient_phone'] ?? ($customer['phone'] ?? ''),
    ]);
    $notes = trim((string) ($invoice['notes'] ?? ''));
    $terms = trim((string) ($invoice['terms'] ?? ''));
    return '<!doctype html><html lang="en"><head><meta charset="UTF-8">' . $viewportMeta . $robotsMeta . '<title>'
        . e((string) ($invoice['document_title'] ?: 'Invoice')) . ' ' . e((string) $invoice['invoice_number'])
        . '</title><style>
            @page { margin: 26px 28px; }
            * { box-sizing: border-box; }
            body { margin: 0; color: ' . $theme['ink'] . '; font-family: DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1.5; }
            .document { border-top: 5px solid ' . $theme['accent'] . '; padding-top: 24px; }
            table { border-collapse: collapse; width: 100%; }
            .header td { vertical-align: top; padding-bottom: 20px; border-bottom: 1px solid #e6e5e9; }
            .issuer { width: 55%; }
            .issuer-mark { display: inline-block; width: 38px; height: 38px; line-height: 38px; margin-right: 10px; background: ' . $theme['soft'] . '; color: ' . $theme['accent'] . '; font-size: 18px; font-weight: bold; text-align: center; vertical-align: top; }
            .logo { max-width: 38px; max-height: 38px; }
            .issuer-info { display: inline-block; max-width: 78%; vertical-align: top; }
            .issuer-name { display: block; margin: 2px 0 3px; font-size: 13pt; font-weight: bold; }
            .issuer-contact { color: #777482; font-size: 9pt; }
            .heading { text-align: right; }
            .kicker { color: ' . $theme['accent'] . '; font-size: 8pt; font-weight: bold; letter-spacing: 1px; }
            h1 { margin: 2px 0; color: ' . $theme['ink'] . '; font-size: 25pt; line-height: 1.2; }
            .invoice-number { color: #777482; font-size: 9pt; }
            .parties { margin: 24px 0 27px; }
            .parties td { width: 38%; padding-right: 16px; vertical-align: top; }
            .parties .dates { width: 24%; padding: 10px; background: ' . $theme['soft'] . '; }
            .label { display: block; margin-bottom: 5px; color: #777482; font-size: 8pt; font-weight: bold; letter-spacing: .8px; }
            .party-name { display: block; margin-bottom: 3px; font-size: 10pt; font-weight: bold; }
            .party-line { display: block; color: #676573; font-size: 9pt; overflow-wrap: anywhere; }
            .date-item { margin-bottom: 8px; }
            .date-item:last-child { margin-bottom: 0; }
            .date-value { display: block; font-size: 9pt; font-weight: bold; }
            .status { color: ' . $theme['accent'] . '; }
            .items { margin: 0 0 24px; }
            .items thead { display: table-header-group; }
            .items th { padding: 10px 8px; border-bottom: 1px solid #dcdbe2; background: ' . $theme['soft'] . '; color: #5e5b68; font-size: 8pt; text-align: left; }
            .items td { padding: 11px 8px; border-bottom: 1px solid #ecebf0; font-size: 9pt; vertical-align: top; }
            .items tr { page-break-inside: avoid; }
            .number { text-align: right !important; white-space: nowrap; }
            .summary { page-break-inside: avoid; }
            .summary td { width: 50%; padding: 0 0 16px; vertical-align: top; }
            .summary td:last-child { padding-left: 20px; }
            .message { margin-bottom: 14px; color: #676573; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 9pt; }
            .totals tr td { padding: 7px 8px; border-bottom: 1px solid #ecebf0; color: #676573; font-size: 9pt; }
            .totals tr td:last-child { text-align: right; white-space: nowrap; }
            .totals .grand td { padding: 11px 9px; background: ' . $theme['soft'] . '; color: ' . $theme['accent'] . '; font-size: 11pt; font-weight: bold; }
            .totals .balance td { color: ' . $theme['ink'] . '; font-weight: bold; }
            .footer { margin-top: 22px; padding-top: 12px; border-top: 1px solid #e6e5e9; color: #9996a1; font-size: 8pt; }
        ' . $templateLayoutStyles . $browserStyles . '
        </style></head><body><main class="document template-' . e($template) . '">
        <table class="header"><tr><td class="issuer"><span class="issuer-mark">'
        . ($logoMarkup !== '' ? $logoMarkup : e($businessInitial))
        . '</span><span class="issuer-info"><strong class="issuer-name">' . e($issuerName)
        . '</strong><span class="issuer-contact">' . e($issuerEmail ?: $issuerPhone) . '</span></span></td><td class="heading">'
        . '<span class="kicker">' . e((string) ($invoice['document_label'] ?: 'PAYMENT REQUEST')) . '</span><h1>'
        . e((string) ($invoice['document_title'] ?: 'Invoice')) . '</h1><span class="invoice-number">'
        . e((string) $invoice['invoice_number']) . '</span></td></tr></table>'
        . '<table class="parties"><tr><td><span class="label">FROM</span><strong class="party-name">' . e($issuerName) . '</strong>'
        . implode('', array_map(static fn(string $line): string => '<span class="party-line">' . e($line) . '</span>', $issuerLines))
        . '</td><td><span class="label">BILL TO</span><strong class="party-name">' . e($recipientName) . '</strong>'
        . implode('', array_map(static fn(string $line): string => '<span class="party-line">' . e((string) $line) . '</span>', $recipientLines))
        . '</td><td class="dates"><div class="date-item"><span class="label">ISSUED</span><strong class="date-value">'
        . e($issueDate) . '</strong></div><div class="date-item"><span class="label">DUE DATE</span><strong class="date-value">'
        . e($dueDate) . '</strong></div><div class="date-item"><span class="label">STATUS</span><strong class="date-value status">'
        . e($invoiceStatus) . '</strong></div></td></tr></table>'
        . ($forBrowser ? '<div class="items-scroll">' : '')
        . '<table class="items"><thead><tr><th>Description</th><th class="number">Qty</th><th class="number">Rate</th><th class="number">Tax</th><th class="number">Amount</th></tr></thead><tbody>'
        . implode('', $descriptionLines)
        . '</tbody></table>' . ($forBrowser ? '</div>' : '') . '<table class="summary"><tr><td>'
        . ($notes !== '' ? '<div class="message"><span class="label">A NOTE</span>' . nl2br(e($notes)) . '</div>' : '')
        . ($terms !== '' ? '<div class="message"><span class="label">PAYMENT TERMS</span>' . nl2br(e($terms)) . '</div>' : '')
        . '</td><td><table class="totals"><tr><td>Subtotal</td><td>' . e(formatMoney($subtotal, $currency))
        . '</td></tr>' . ($discount > 0 ? '<tr><td>Discount</td><td>-' . e(formatMoney($discount, $currency)) . '</td></tr>' : '')
        . '<tr><td>Tax</td><td>' . e(formatMoney($tax, $currency)) . '</td></tr><tr class="grand"><td>Total</td><td>'
        . e(formatMoney($total, $currency)) . '</td></tr><tr><td>Amount paid</td><td>' . e(formatMoney($amountPaid, $currency))
        . '</td></tr><tr class="balance"><td>Balance due</td><td>' . e(formatMoney($balance, $currency))
        . '</td></tr></table></td></tr></table><footer class="footer">' . e($issuerName) . ' · '
        . e($issuerEmail ?: $issuerPhone) . '</footer></main></body></html>';
}

function renderPaymentReceiptPdfHtml(array $payment, string $logoMarkup = ''): string
{
    return '<!doctype html><html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#252331;font-size:12px;line-height:1.6;margin:40px}h1{color:#1f6b54}table{width:100%;border-collapse:collapse;margin-top:24px}td{padding:10px;border-bottom:1px solid #e8e4f2}.label{color:#73738a}.amount{font-size:20px;font-weight:bold;color:#1f6b54}</style></head><body>'
        . $logoMarkup . '<h1>Payment receipt</h1><p>Receipt for payment toward invoice <strong>' . e((string) $payment['invoice_number']) . '</strong>.</p>'
        . '<table><tr><td class="label">Received by</td><td>' . e((string) $payment['business_name']) . '</td></tr>'
        . '<tr><td class="label">Customer</td><td>' . e((string) ($payment['customer_company'] ?: $payment['customer_name'] ?: 'Customer')) . '</td></tr>'
        . '<tr><td class="label">Payment date</td><td>' . e((string) $payment['payment_date']) . '</td></tr>'
        . '<tr><td class="label">Method</td><td>' . e(ucwords(str_replace('_', ' ', (string) $payment['method']))) . '</td></tr>'
        . '<tr><td class="label">Reference</td><td>' . e((string) ($payment['reference'] ?: '—')) . '</td></tr>'
        . '<tr><td class="label">Amount received</td><td class="amount">' . e(formatMoney((float) $payment['amount'], (string) $payment['currency'])) . '</td></tr></table>'
        . '<p>' . e((string) $payment['business_name']) . ' · ' . e((string) ($payment['business_email'] ?? '')) . '</p></body></html>';
}
