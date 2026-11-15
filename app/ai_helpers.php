<?php

function buildAiTotalsExplanation(array $totals, string $currency): string
{
    $subtotal = (float) ($totals['subtotal'] ?? 0);
    $discount = (float) ($totals['discount'] ?? 0);
    $tax = (float) ($totals['tax'] ?? 0);
    $total = (float) ($totals['total'] ?? 0);

    if ($subtotal === 0.0 && $discount === 0.0 && $tax === 0.0 && $total === 0.0) {
        return 'All line amounts are currently zero. Enter quantities and unit prices to calculate a meaningful invoice total.';
    }

    $explanation = 'The subtotal after line-item discounts is ' . formatMoney($subtotal, $currency) . '.';
    if ($discount > 0) {
        $explanation .= ' Discounts reduce the line amounts by ' . formatMoney($discount, $currency) . ' before tax.';
    }
    $explanation .= ' Tax adds ' . formatMoney($tax, $currency) . ', making the invoice total ' . formatMoney($total, $currency) . '.';

    return $explanation;
}

function buildAiPaymentReminder(array $invoice, array $paymentSummary): array
{
    $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
    $customerName = trim((string) ($invoice['customer_name'] ?? '')) ?: 'Customer';
    $currency = (string) ($invoice['currency'] ?? 'USD');
    $balance = formatMoney(((int) ($paymentSummary['balance_cents'] ?? 0)) / 100, $currency);
    $dueDate = trim((string) ($invoice['due_date'] ?? ''));
    $daysOverdue = (int) ($invoice['days_overdue'] ?? 0);
    $subjectPrefix = $daysOverdue > 0 ? 'Overdue payment reminder' : 'Payment reminder';
    $subject = $subjectPrefix . ': invoice ' . $invoiceNumber;
    $body = "Hello {$customerName},\n\nThis is a friendly reminder that invoice {$invoiceNumber} has an outstanding balance of {$balance}.";
    if ($dueDate !== '') {
        $body .= "\nThe due date was {$dueDate}.";
    }
    $body .= "\n\nIf you have already made this payment, please disregard this message. Thank you.";

    return ['subject' => $subject, 'text' => $body];
}

function buildAiBusinessSummary(array $data): string
{
    $invoiceCount = (int) ($data['total_invoices'] ?? 0);
    $customerCount = (int) ($data['total_customers'] ?? 0);
    $lines = [
        'Workspace activity: ' . $invoiceCount . ' ' . ($invoiceCount === 1 ? 'invoice' : 'invoices') . ' and '
            . $customerCount . ' ' . ($customerCount === 1 ? 'customer' : 'customers') . '.',
    ];

    $amounts = $data['amounts_grouped_by_currency_and_status'] ?? [];
    foreach ($amounts as $row) {
        if (!is_array($row)) {
            continue;
        }
        $currency = (string) ($row['currency'] ?? 'USD');
        $status = (string) ($row['status'] ?? 'Unpaid');
        $count = (int) ($row['invoice_count'] ?? 0);
        $amount = formatMoney((float) ($row['total'] ?? 0), $currency);
        $lines[] = $status . ': ' . $amount . ' across ' . $count . ' ' . ($count === 1 ? 'invoice' : 'invoices') . '.';
    }
    if (!$amounts) {
        $lines[] = 'No issued invoices are currently included in the financial totals.';
    }

    $overdueInvoices = $data['overdue_invoices'] ?? [];
    if (!$overdueInvoices) {
        $lines[] = 'There are no overdue invoices.';
    } else {
        $lines[] = 'Overdue invoices:';
        foreach (array_slice($overdueInvoices, 0, 5) as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }
            $lines[] = '- ' . (string) ($invoice['invoice_number'] ?? 'Invoice')
                . ' for ' . (string) ($invoice['customer_name'] ?? 'Customer')
                . ': ' . formatMoney((float) ($invoice['balance'] ?? 0), (string) ($invoice['currency'] ?? 'USD'))
                . ' due ' . (string) ($invoice['due_date'] ?? 'date not specified') . '.';
        }
        if (count($overdueInvoices) > 5) {
            $lines[] = 'And ' . (count($overdueInvoices) - 5) . ' more overdue invoices.';
        }
    }

    return implode("\n", $lines);
}

function buildAiQuickChatResponse(string $question, array $navigationPages): ?array
{
    $intents = [
        [
            'pattern' => '/\b(record|enter|add|log|mark|track)\b.{0,30}\b(payment|paid)\b|\b(payment|paid)\b.{0,30}\b(record|enter|add|log|mark|track)\b/i',
            'destination' => 'invoices',
            'text' => 'To record a payment, open Invoices, select the issued invoice, expand “View all payment details,” and use “Record payment.” Enter the amount, date, and method; the remaining balance updates from recorded payments.',
        ],
        [
            'pattern' => '/\b(remind(er)?|overdue)\b/i',
            'destination' => 'invoices',
            'text' => 'Open the invoice to review its balance and due date. Use “Draft a payment reminder” to prepare a message, then review and send it yourself.',
        ],
        [
            'pattern' => '/\b(payment|paid|balance)\b/i',
            'destination' => 'invoices',
            'text' => 'Open Invoices and select an invoice to review its payment records and remaining balance.',
        ],
        [
            'pattern' => '/\b(create|make|new|start|draft)\b.{0,40}\binvoice\b|\binvoice\b.{0,40}\b(create|make|new|start|draft)\b/i',
            'destination' => 'new_invoice',
            'text' => 'To create an invoice, open New Invoice, choose a design, add the customer and line items, then save the draft. Review it before issuing.',
        ],
        ['pattern' => '/\b(template|design)\b/i', 'destination' => 'templates', 'text' => 'Open Templates to choose an invoice design.'],
        ['pattern' => '/\b(customer|client|contact)\b/i', 'destination' => 'customers', 'text' => 'Open Customers to add or manage customer records.'],
        ['pattern' => '/\b(product|service|catalog)\b/i', 'destination' => 'products', 'text' => 'Open Products to manage saved products and services.'],
        ['pattern' => '/\b(expense|receipt)\b/i', 'destination' => 'expenses', 'text' => 'Open Expenses to record a business expense or attach its receipt.'],
        ['pattern' => '/\b(quotation|quote)\b/i', 'destination' => 'quotes', 'text' => 'Open Quotes to create and manage customer quotations.'],
        ['pattern' => '/\b(report|receivable|collection)\b/i', 'destination' => 'reports', 'text' => 'Open Reports to review collections, expenses, and receivables.'],
        ['pattern' => '/\b(setting|profile|business details)\b/i', 'destination' => 'settings', 'text' => 'Open Settings to update your business and workspace details.'],
        ['pattern' => '/\b(dashboard|overview)\b/i', 'destination' => 'dashboard', 'text' => 'Open Overview to see workspace metrics and recent activity.'],
        ['pattern' => '/\binvoice(s)?\b/i', 'destination' => 'invoices', 'text' => 'Open Invoices to view saved invoices and their status.'],
    ];

    foreach ($intents as $intent) {
        if (isset($navigationPages[$intent['destination']]) && preg_match($intent['pattern'], $question) === 1) {
            return ['text' => $intent['text'], 'destination' => $intent['destination']];
        }
    }

    return null;
}
