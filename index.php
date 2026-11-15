<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/seo.php';

if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$pageTitle = 'Professional invoice software';
$siteTitle = 'Free Online Invoice Generator | Invoices Generators';
$metaDescription = 'Create invoices online from anywhere. Bill in USD or XAF (FCFA), download client-ready PDFs and manage clients. Built with Cameroon businesses in mind and available worldwide.';
$seoPagePath = '';
$seoLocale = 'en_CM';
$htmlLang = 'en';
$seoAlternates = [
    'en' => '',
    'fr-CM' => 'facturation-cameroun',
    'x-default' => '',
];
$seoStructuredData = seoLandingStructuredData('', $seoLocale, $siteTitle, $metaDescription, [
    'Can I create invoices in FCFA in Cameroon?' => 'Yes. Choose XAF (FCFA) as the invoice currency, then download or share the finished invoice as a PDF.',
    'Can I create invoices in PDF format?' => 'Yes. Create an invoice in your workspace and download or share a print-ready PDF.',
    'Can I try it before paying?' => 'Yes. The Free plan includes up to 10 invoices per month, 25 customers and products, two invoice designs, and 10 AI requests per month.',
]);
include __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="container hero-grid">
        <div class="hero-copy" data-reveal>
            <div class="hero-heading">
                <span class="eyebrow"><span class="eyebrow-dot"></span>For businesses in Cameroon and around the world</span>
                <h1>Create professional invoices.<br>Make sure you <em>get paid.</em></h1>
            </div>
            <p class="lead">Create invoices from anywhere, with clear PDFs, client management and support for XAF (FCFA) or USD. Made with Cameroon businesses in mind and ready for freelancers and small businesses worldwide.</p>
            <div class="button-row">
                <a class="btn btn-primary" href="register.php">
                    Create your first invoice
                    <svg aria-hidden="true" viewBox="0 0 20 20"><path d="M4 10h11m-4-4 4 4-4 4"/></svg>
                </a>
                <a class="btn btn-text" href="#features">Take a closer look</a>
            </div>
            <div class="hero-proof">
                <span>Thoughtful by design</span>
                <i></i>
                <span>Simple from day one</span>
            </div>
        </div>

        <div class="hero-visual" data-reveal>
            <div class="product-stage">
                <div class="product-window">
                    <aside class="product-rail">
                        <a class="product-wordmark" href="index.php"><span>i</span> invoice</a>
                        <span class="rail-label">WORKSPACE</span>
                        <nav class="product-menu" aria-label="Product preview navigation">
                            <a class="selected" href="#features"><svg viewBox="0 0 20 20"><rect x="3" y="3" width="5" height="5" rx="1"/><rect x="12" y="3" width="5" height="5" rx="1"/><rect x="3" y="12" width="5" height="5" rx="1"/><rect x="12" y="12" width="5" height="5" rx="1"/></svg>Overview</a>
                            <a href="#templates"><svg viewBox="0 0 20 20"><path d="M5 2.8h7l4 4v10.4H5a2 2 0 0 1-2-2V4.8a2 2 0 0 1 2-2Z"/><path d="M12 3v4h4M7 11h6M7 14h6"/></svg>Invoices<span>8</span></a>
                            <a href="#features"><svg viewBox="0 0 20 20"><circle cx="7" cy="6.5" r="2.5"/><path d="M2.5 16v-1a4.5 4.5 0 0 1 9 0v1M14 5a2.5 2.5 0 0 1 0 5m1 2a4 4 0 0 1 2.5 3.7v.3"/></svg>Clients</a>
                            <a href="#pricing"><svg viewBox="0 0 20 20"><path d="M3 16.5V10m4.7 6.5V5m4.6 11.5v-4m4.7 4V3.5M2 18h16"/></svg>Reports</a>
                        </nav>
                        <div class="rail-account">
                            <span class="rail-avatar">NS</span>
                            <span><strong>Northstar Studio</strong><small>Free plan</small></span>
                        </div>
                    </aside>
                    <div class="product-content">
                        <div class="product-topbar"><span>MONDAY, 12 MAY 2026</span><span class="preview-dot"></span><span>Sample workspace</span></div>
                        <div class="product-heading">
                            <div><small>YOUR BUSINESS AT A GLANCE</small><h2>Good morning, Alex</h2></div>
                            <a href="register.php">+ New invoice</a>
                        </div>
                        <div class="product-metrics">
                            <article><span>Collected this month</span><strong>$8,420</strong><small><b>↑ 12.8%</b> vs last month</small><i class="sparkline sparkline-green"></i></article>
                            <article><span>Awaiting payment</span><strong>$2,180</strong><small>Across <b>4 invoices</b></small><i class="sparkline sparkline-violet"></i></article>
                            <article><span>Active clients</span><strong>18</strong><small><b>3 new</b> this month</small><i class="sparkline sparkline-amber"></i></article>
                        </div>
                        <div class="product-lower">
                            <section class="product-chart">
                                <div class="product-card-heading"><span><strong>Cash flow</strong><small>Income received over time</small></span><span class="chart-period">Last 6 months⌄</span></div>
                                <svg viewBox="0 0 420 150" role="img" aria-label="Illustrative cash flow trend chart">
                                    <defs><linearGradient id="chartFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#9d8aed" stop-opacity=".3"/><stop offset="1" stop-color="#9d8aed" stop-opacity="0"/></linearGradient></defs>
                                    <path class="chart-gridline" d="M0 25H420M0 65H420M0 105H420M0 145H420"/>
                                    <path class="chart-area" d="M0 115 C28 110 35 81 67 88 S112 112 139 78 S183 90 210 68 S251 81 279 44 S325 66 349 40 S390 47 420 18 V150 H0Z"/>
                                    <path class="chart-line" d="M0 115 C28 110 35 81 67 88 S112 112 139 78 S183 90 210 68 S251 81 279 44 S325 66 349 40 S390 47 420 18"/>
                                    <circle class="chart-point" cx="349" cy="40" r="4"/>
                                </svg>
                                <div class="chart-months"><span>DEC</span><span>JAN</span><span>FEB</span><span>MAR</span><span>APR</span><span>MAY</span></div>
                            </section>
                            <section class="product-due">
                                <div class="product-card-heading"><span><strong>Recent invoices</strong><small>Latest activity</small></span><a href="login.php">View all</a></div>
                                <div class="due-row"><span class="due-avatar due-lilac">JM</span><span class="due-name"><b>Jordan Mitchell</b><small>INV-1048 · Due today</small></span><strong>$1,240</strong></div>
                                <div class="due-row"><span class="due-avatar due-peach">AL</span><span class="due-name"><b>Atlas & Co.</b><small>INV-1047 · May 18</small></span><strong>$860</strong></div>
                                <div class="due-row"><span class="due-avatar due-sage">SK</span><span class="due-name"><b>Sarah Kim</b><small>INV-1046 · Paid</small></span><strong>$2,100</strong></div>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
            <div class="stage-caption"><span>01</span> A clearer view of your business <i></i> <span>Invoice workspace</span></div>
        </div>
    </div>
</section>

<section id="features" class="section features-section">
    <div class="container">
        <div class="section-header">
            <span class="eyebrow">A better billing routine</span>
            <h2>Less chasing. More clarity.</h2>
            <p>All the everyday essentials, thoughtfully brought together.</p>
        </div>
        <div class="grid-3">
            <article class="feature-card" data-reveal>
                <div class="feature-card-top">
                    <span class="feature-index">01 / CREATE</span>
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M7 3.75h7l4 4v12.5H7a2 2 0 0 1-2-2v-12.5a2 2 0 0 1 2-2Z"/><path d="M14 4v4h4M8.5 12h7M8.5 15.5h7"/></svg>
                    </span>
                </div>
                <h3>Create invoices in FCFA or USD</h3>
                <p>Generate clear, professional invoices in XAF (FCFA) or USD, with the details clients need and totals that calculate as you work.</p>
                <span class="feature-note"><i></i> Ready to send in minutes</span>
            </article>
            <article class="feature-card" data-reveal>
                <div class="feature-card-top">
                    <span class="feature-index">02 / ORGANIZE</span>
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.25"/><path d="M3.5 19v-1.25a5.5 5.5 0 0 1 11 0V19M16 5.2a3.25 3.25 0 0 1 0 6.3m1 2.2a5.4 5.4 0 0 1 3.5 5.05V19"/></svg>
                    </span>
                </div>
                <h3>Clients, all in one place</h3>
                <p>Keep contact details and billing history close at hand, so every client relationship stays easy to manage.</p>
                <span class="feature-note"><i></i> Every detail, right where you need it</span>
            </article>
            <article class="feature-card" data-reveal>
                <div class="feature-card-top">
                    <span class="feature-index">03 / TRACK</span>
                    <span class="feature-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 18.5V12m5 6.5V7m5 11.5v-4m5 4V4.5"/><path d="M3 20.5h18"/></svg>
                    </span>
                </div>
                <h3>Know what is outstanding</h3>
                <p>See what has been paid and what still needs attention, without piecing the numbers together yourself.</p>
                <span class="feature-note"><i></i> A clearer view of your cash flow</span>
            </article>
        </div>
        <div class="features-footnote"><span>MADE FOR INDEPENDENT WORK</span><span>Less admin, more momentum <i>↗</i></span></div>
    </div>
</section>

<section class="workflow-section">
    <div class="container workflow-grid">
        <div class="workflow-copy" data-reveal>
            <span class="eyebrow">More time for your work</span>
            <h2>Keep the business moving. Leave the admin behind.</h2>
            <p>From the first line item to the final payment, your billing workflow stays simple, clear, and ready when you are.</p>
            <a class="workflow-link" href="register.php">
                Make your first invoice
                <svg aria-hidden="true" viewBox="0 0 20 20"><path d="M4 10h11m-4-4 4 4-4 4"/></svg>
            </a>
        </div>
        <figure class="workflow-image" data-reveal>
            <img src="<?= e(publicAssetUrl('public/images/team-work.jpg')); ?>" alt="Creative team collaborating around a table" width="1200" height="1799" loading="lazy" decoding="async">
            <figcaption>Good work deserves a smoother back office.</figcaption>
        </figure>
    </div>
</section>
<section id="templates" class="section templates-section">
    <div class="container">
        <div class="section-header section-header-split">
            <div>
                <span class="eyebrow">Your work, well presented</span>
                <h2>Make a good first impression.</h2>
            </div>
            <p>Choose a clean, client-ready layout. Add your details and send an invoice that looks like it came from you.</p>
        </div>
        <div class="template-grid">
            <article class="template-card">
                <div class="template-sample template-sample-classic" aria-hidden="true">
                    <div class="sample-top">
                        <span class="sample-brand">northstar <small>studio</small></span>
                        <strong>INVOICE</strong>
                    </div>
                    <div class="sample-info">
                        <span><small>INVOICE NO.</small><b>INV-1048</b></span>
                        <span><small>BILL TO</small><b>Acme Studio</b></span>
                    </div>
                    <div class="sample-items">
                        <div><b>Website design</b><span>$1,200.00</span></div>
                        <div><b>Content direction</b><span>$285.00</span></div>
                    </div>
                    <div class="sample-bottom"><span>Thank you for your business</span><strong>$1,485.00</strong></div>
                </div>
                <div class="template-card-info"><h3>Classic</h3><span>Timeless & clear</span></div>
                <p>Traditional invoice layout with a clean, trusted structure for formal business billing.</p>
            </article>
            <article class="template-card">
                <div class="template-sample template-sample-modern" aria-hidden="true">
                    <div class="sample-top">
                        <span class="sample-brand">northstar <small>studio</small></span>
                        <strong>INVOICE</strong>
                    </div>
                    <div class="sample-info">
                        <span><small>INVOICE NO.</small><b>INV-1048</b></span>
                        <span><small>BILL TO</small><b>Acme Studio</b></span>
                    </div>
                    <div class="sample-items">
                        <div><b>Website design</b><span>$1,200.00</span></div>
                        <div><b>Content direction</b><span>$285.00</span></div>
                    </div>
                    <div class="sample-bottom"><span>Thank you for your business</span><strong>$1,485.00</strong></div>
                </div>
                <div class="template-card-info"><h3>Modern</h3><span>Confident & considered</span></div>
                <p>Contemporary styling with clear hierarchy and stronger premium brand presence.</p>
            </article>
            <article class="template-card">
                <div class="template-sample template-sample-minimal" aria-hidden="true">
                    <div class="sample-top">
                        <span class="sample-brand">northstar <small>studio</small></span>
                        <strong>INVOICE</strong>
                    </div>
                    <div class="sample-info">
                        <span><small>INVOICE NO.</small><b>INV-1048</b></span>
                        <span><small>BILL TO</small><b>Acme Studio</b></span>
                    </div>
                    <div class="sample-items">
                        <div><b>Website design</b><span>$1,200.00</span></div>
                        <div><b>Content direction</b><span>$285.00</span></div>
                    </div>
                    <div class="sample-bottom"><span>Thank you for your business</span><strong>$1,485.00</strong></div>
                </div>
                <div class="template-card-info"><h3>Minimal</h3><span>Quietly distinctive</span></div>
                <p>Elegant, distraction-free design with maximum readability for straightforward business needs.</p>
            </article>
        </div>
        <div class="templates-cta"><span>Every template is ready to make your own.</span><a href="register.php">Find your starting point <b>↗</b></a></div>
    </div>
</section>

<section id="pricing" class="section pricing-section">
    <div class="container">
        <div class="section-header">
            <span class="eyebrow">Simple, transparent pricing</span>
            <h2>Start free. Grow when you’re ready.</h2>
            <p>Choose Free, Pro or Business. Cameroon accounts are billed in FCFA; international accounts are billed in USD.</p>
        </div>
        <div class="plan-grid">
            <article class="plan-card plan-card-free">
                <div class="plan-card-heading">
                    <div><span class="plan-audience">A GOOD PLACE TO BEGIN</span><h3>Free</h3></div>
                </div>
                <p class="plan-promise">Send a professional first invoice without paying a thing.</p>
                <div class="plan-preview plan-preview-free" aria-hidden="true">
                    <div class="preview-paper">
                        <span class="preview-paper-brand"></span><span class="preview-paper-label">INVOICE</span>
                        <span class="preview-paper-line preview-paper-line-long"></span><span class="preview-paper-line"></span>
                        <span class="preview-paper-total"><i></i><b></b></span>
                    </div>
                    <span class="preview-caption">A polished invoice, ready to share</span>
                </div>
                <div class="plan-price">
                    <span class="plan-price-main">0</span>
                    <span class="plan-price-detail">Free forever · FCFA or USD</span>
                </div>
                <div class="plan-highlights" aria-label="Free plan highlights">
                    <div><span>Invoices</span><strong>10 <small>/ month</small></strong></div>
                    <div><span>Clients & products</span><strong>25 each</strong></div>
                    <div><span>Invoice styles</span><strong>2 designs</strong></div>
                    <div><span>AI writing help</span><strong>10 <small>/ month</small></strong></div>
                </div>
                <div class="plan-includes"><span class="plan-include-icon">✓</span><span>Essential dashboard insights</span><span class="plan-not-included">Quotations not included</span></div>
                <a href="register.php" class="btn btn-primary">Create an account</a>
            </article>
            <article class="plan-card plan-card-pro featured">
                <div class="plan-card-heading">
                    <div><span class="plan-audience">FOR YOUR NEXT STAGE</span><h3>Pro</h3></div>
                    <span class="plan-featured-tag">MOST POPULAR</span>
                </div>
                <p class="plan-promise">Look your best, serve more clients, and stay on top of the details.</p>
                <div class="plan-preview plan-preview-pro" aria-hidden="true">
                    <div class="preview-template preview-template-one"><i></i><b></b><span></span><span></span><em></em></div>
                    <div class="preview-template preview-template-two"><i></i><b></b><span></span><span></span><em></em></div>
                    <div class="preview-template preview-template-three"><i></i><b></b><span></span><span></span><em></em></div>
                    <span class="preview-count">20 designs</span>
                </div>
                <div class="plan-price">
                    <span class="plan-price-main">1,200 FCFA</span>
                    <span class="plan-price-detail">per month · about $3 internationally</span>
                </div>
                <div class="plan-highlights" aria-label="Pro plan highlights">
                    <div><span>Invoices</span><strong>100 <small>/ month</small></strong></div>
                    <div><span>Clients & products</span><strong>500 each</strong></div>
                    <div><span>Invoice styles</span><strong>All 20</strong></div>
                    <div><span>AI writing help</span><strong>100 <small>/ month</small></strong></div>
                </div>
                <div class="plan-includes"><span class="plan-include-icon">✓</span><span>Quotations & advanced reports</span><span class="plan-included-tag">Included</span></div>
                <a href="register.php" class="btn btn-primary">Choose Pro</a>
            </article>
            <article class="plan-card plan-card-business">
                <div class="plan-card-heading">
                    <div><span class="plan-audience">FOR ESTABLISHED WORKFLOWS</span><h3>Business</h3></div>
                </div>
                <p class="plan-promise">More room for a busy client list and a fuller month of invoicing.</p>
                <div class="plan-preview plan-preview-business" aria-hidden="true">
                    <div class="preview-dashboard">
                        <span class="preview-dashboard-label">INVOICES THIS MONTH</span>
                        <div class="preview-dashboard-number"><b>More room to grow</b><i>↑</i></div>
                        <div class="preview-chart"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                        <div class="preview-dashboard-foot"><span>Clients</span><b>Organized</b></div>
                    </div>
                    <span class="preview-capacity">Up to 500 invoices / month</span>
                </div>
                <div class="plan-price">
                    <span class="plan-price-main">5,000 FCFA</span>
                    <span class="plan-price-detail">per year · about $9 internationally</span>
                </div>
                <div class="plan-highlights" aria-label="Business plan highlights">
                    <div><span>Invoices</span><strong>500 <small>/ month</small></strong></div>
                    <div><span>Clients & products</span><strong>2,500 each</strong></div>
                    <div><span>Invoice styles</span><strong>All 20</strong></div>
                    <div><span>AI writing help</span><strong>500 <small>/ month</small></strong></div>
                </div>
                <div class="plan-includes"><span class="plan-include-icon">✓</span><span>Quotations & advanced reports</span><span class="plan-included-tag">Included</span></div>
                <a href="register.php" class="btn btn-secondary">Choose Business</a>
            </article>
        </div>
        <p class="muted-copy">International prices are rounded USD equivalents. Select your country when creating your account to set the right currency. Paid plans are prepaid, do not renew automatically, and are activated only after confirmed payment. Provider setup is required before checkout is enabled.</p>
    </div>
</section>

<section id="faq" class="section faq-section">
    <div class="container">
        <div class="section-header">
            <span class="eyebrow">FAQ</span>
            <h2>Questions people ask before getting started.</h2>
        </div>
        <div class="faq-grid">
            <details class="faq-item">
                <summary>Can I create invoices in PDF format?<span aria-hidden="true">+</span></summary>
                <p>Yes. Create an invoice in your workspace and download or share a print-ready PDF.</p>
            </details>
            <details class="faq-item">
                <summary>Can I create invoices in FCFA in Cameroon?<span aria-hidden="true">+</span></summary>
                <p>Yes. Choose XAF (FCFA) as the invoice currency, then download or share the finished invoice as a PDF.</p>
            </details>
            <details class="faq-item">
                <summary>Do you support multiple currencies?<span aria-hidden="true">+</span></summary>
                <p>Invoice and report amounts are grouped by currency; totals in different currencies are never added together.</p>
            </details>
            <details class="faq-item">
                <summary>Is it suitable for freelancers?<span aria-hidden="true">+</span></summary>
                <p>Yes. The dashboard is designed to help self-employed professionals track revenue, customers and payment reminders.</p>
            </details>
            <details class="faq-item">
                <summary>Can I try it before paying?<span aria-hidden="true">+</span></summary>
                <p>Yes. The Free plan includes up to 10 invoices per month, 25 customers and products, two invoice designs, and 10 AI requests per month.</p>
            </details>
        </div>
    </div>
</section>

<section class="trustpilot-review-section" aria-label="Trustpilot reviews">
    <div class="container">
        <div class="trustpilot-review-card">
            <div class="trustpilot-review-copy">
                <span class="trustpilot-review-label">Customer feedback</span>
                <h2>Read our reviews on Trustpilot</h2>
                <p>Visit our profile to see customer feedback directly on Trustpilot.</p>
            </div>
            <a class="trustpilot-review-link" href="https://www.trustpilot.com/review/invoicesgenerators.site" target="_blank" rel="noopener noreferrer">
                View Trustpilot profile <span aria-hidden="true">↗</span>
            </a>
        </div>
    </div>
</section>

<section class="closing-section">
    <div class="container closing-panel" data-reveal>
        <div class="closing-orbit" aria-hidden="true"></div>
        <span class="eyebrow"><span class="eyebrow-dot"></span>Your next invoice can be different</span>
        <h2>Make room for the work you love.</h2>
        <p>Bring your billing into one clear, considered workspace.</p>
        <a class="btn btn-primary" href="register.php">Get started for free <svg aria-hidden="true" viewBox="0 0 20 20"><path d="M4 10h11m-4-4 4 4-4 4"/></svg></a>
        <span class="closing-note">No complicated setup. Just a better way to invoice.</span>
    </div>
</section>

<footer class="footer">
    <div class="container footer-grid">
        <div>
            <a href="index.php" class="brand-link" style="color:#fff; margin-bottom: 18px;">
                <span class="brand-mark">I</span>
                <span>Invoices Gen</span>
            </a>
            <p>Professional invoice and billing software designed for freelancers, agencies and growing businesses.</p>
        </div>
        <div>
            <h2>Company</h2>
            <a href="#features">Features</a>
            <a href="#pricing">Pricing</a>
            <a href="#templates">Templates</a>
        </div>
        <div>
            <h2>Resources</h2>
            <a href="login.php">Login</a>
            <a href="register.php">Get Started</a>
            <a href="help.php">Help center</a>
            <a href="facturation-cameroun" lang="fr">Français</a>
        </div>
    </div>
</footer>

<?php include __DIR__ . '/includes/footer.php'; ?>
