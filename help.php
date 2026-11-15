<?php
require_once __DIR__ . '/app/init.php';

$pageTitle = 'Help Centre';
$siteTitle = 'Help Centre | Invoices Generators';
$metaDescription = 'Find answers about creating invoices, sharing customer documents, using the AI assistant and choosing a plan with Invoices Generators.';
$seoPagePath = 'help';
$seoLocale = 'en_CM';
include __DIR__ . '/includes/header.php';
?>
<div class="container section faq-section">
    <div class="section-header"><span class="eyebrow">Help Centre</span><h1>Answers for your workspace</h1><p>Guidance for the invoice prototype as it currently works.</p></div>
    <div class="faq-grid">
        <details class="faq-item" open><summary>How do I create and download an invoice?<span aria-hidden="true">+</span></summary><p>Sign in, choose New Invoice, select a design, add the customer and line items, then save the draft. Issue it when ready and use the PDF action to download a print-ready copy.</p></details>
        <details class="faq-item"><summary>Can customers pay an invoice online through this app?<span aria-hidden="true">+</span></summary><p>Customers can view a shared invoice, but invoice payments are recorded by the business after they are received. Subscription checkout is a separate flow for paying for a plan.</p></details>
        <details class="faq-item"><summary>How do I share an invoice or quotation with a customer?<span aria-hidden="true">+</span></summary><p>Create a customer link from the invoice or quotation page. Anyone with the link can view that document, so share it privately. You can rotate or revoke the link later.</p></details>
        <details class="faq-item"><summary>Can a customer accept a quotation online?<span aria-hidden="true">+</span></summary><p>Yes. Create and mark the quotation as sent, then share its customer link. The customer can accept or decline it before its expiry date. An accepted quotation can be converted into a draft invoice.</p></details>
        <details class="faq-item"><summary>How does the AI assistant work?</summary><p>Invoice totals, business summaries, payment reminders, and common workspace directions are prepared directly from your saved data. Invoice drafting and open-ended writing use the server-side Gemini service when configured. Suggestions are never automatically saved, issued, or sent.</p></details>
        <details class="faq-item"><summary>Which subscription plans are available?</summary><p>Free includes 10 invoices/month, 25 customers/products, 2 invoice designs and 10 AI requests/month. Pro includes 100 invoices/month, 500 customers/products, all designs, quotations and 100 AI requests/month for 1,200 FCFA/month in Cameroon or $3/month internationally. Business includes 500 invoices/month, 2,500 customers/products, all designs, quotations and 500 AI requests/month for 5,000 FCFA/year or $9/year.</p></details>
        <details class="faq-item"><summary>How are subscriptions paid?</summary><p>Cameroon accounts can choose Mobile Money in FCFA or cryptocurrency in USD. International accounts use cryptocurrency checkout in USD. Checkout appears only when the site owner has configured the payment services. Plans are prepaid, do not renew automatically, and paid access begins only after payment confirmation. Workspace defaults for invoices, products and expenses use USD; invoices may use other supported currencies.</p></details>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
