<?php

require_once __DIR__ . '/db.php';

function seoSiteBaseUrl(): ?string
{
    $siteUrl = subscriptionPaymentSetting('SEO_SITE_URL');
    if ($siteUrl === '') {
        $siteUrl = subscriptionPaymentSetting('APP_BASE_URL');
    }

    $parts = parse_url($siteUrl);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])
        || strtolower($parts['scheme']) !== 'https'
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        return null;
    }

    $host = strtolower(rtrim((string) $parts['host'], '.'));
    if (filter_var($host, FILTER_VALIDATE_IP) !== false
        || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        || !str_contains($host, '.')) {
        return null;
    }

    $topLevelDomain = strtolower((string) substr($host, (int) strrpos($host, '.') + 1));
    if (in_array($topLevelDomain, ['example', 'invalid', 'localhost', 'local', 'test', 'onion'], true)) {
        return null;
    }

    $path = '/' . trim((string) ($parts['path'] ?? ''), '/');
    $path = $path === '/' ? '' : $path;

    return 'https://' . $host . $path;
}

function seoAbsoluteUrl(string $path = ''): ?string
{
    $baseUrl = seoSiteBaseUrl();
    if ($baseUrl === null || str_contains($path, '\\') || str_starts_with($path, '//')) {
        return null;
    }

    $path = ltrim($path, '/');

    return $baseUrl . ($path === '' ? '/' : '/' . $path);
}

function seoIndexablePage(?string $pagePath, bool $isLoggedIn = false): bool
{
    return !$isLoggedIn && (
        in_array($pagePath, ['', 'index.php', 'facturation-cameroun', 'help', 'blog'], true)
        || ($pagePath !== null && preg_match('/^blog\/[a-z0-9-]+$/', $pagePath) === 1)
    );
}

function seoLandingStructuredData(string $pagePath, string $locale, string $title, string $description, array $faqItems): array
{
    $pageUrl = seoAbsoluteUrl($pagePath);
    if ($pageUrl === null) {
        return [];
    }

    $baseUrl = seoAbsoluteUrl();
    $mainEntity = [];
    foreach ($faqItems as $question => $answer) {
        $mainEntity[] = [
            '@type' => 'Question',
            'name' => $question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $answer,
            ],
        ];
    }

    return [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'WebSite',
                '@id' => $baseUrl . '#website',
                'url' => $baseUrl,
                'name' => 'Invoices Generators',
                'inLanguage' => $locale,
            ],
            [
                '@type' => 'SoftwareApplication',
                '@id' => $baseUrl . '#application',
                'name' => 'Invoices Generators',
                'url' => $pageUrl,
                'description' => $description,
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'inLanguage' => $locale,
                'isAccessibleForFree' => true,
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => 'Invoices Generators',
                    'url' => $baseUrl,
                ],
            ],
            [
                '@type' => 'WebPage',
                '@id' => $pageUrl . '#webpage',
                'url' => $pageUrl,
                'name' => $title,
                'description' => $description,
                'inLanguage' => $locale,
                'isPartOf' => ['@id' => $baseUrl . '#website'],
                'about' => ['@id' => $baseUrl . '#application'],
            ],
            [
                '@type' => 'FAQPage',
                '@id' => $pageUrl . '#faq',
                'url' => $pageUrl . '#faq',
                'inLanguage' => $locale,
                'mainEntity' => $mainEntity,
            ],
        ],
    ];
}

function seoRobotsTxt(): string
{
    $rules = [
        'User-agent: *',
        'Allow: /',
    ];
    $baseUrl = seoSiteBaseUrl();
    $basePath = '';
    if ($baseUrl !== null) {
        $basePath = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
    }

    foreach ([
        'admin.php',
        'admin',
        'dashboard.php',
        'dashboard',
        'invoice.php',
        'invoice',
        'invoices.php',
        'invoices',
        'invoice_create.php',
        'invoice_create',
        'invoice_pdf.php',
        'invoice_pdf',
        'quote_pdf.php',
        'quote_pdf',
        'receipt_pdf.php',
        'receipt_pdf',
        'expense_receipt.php',
        'expense_receipt',
        'portal.php',
        'portal',
        'p/',
        'portal_link.php',
        'portal_link',
        'portal_pdf.php',
        'portal_pdf',
        'subscription_checkout.php',
        'subscription_checkout',
        'subscription_webhook.php',
        'subscription_webhook',
        'ai_invoice_assist.php',
        'ai_invoice_assist',
        'logo.php',
        'logo',
        'app/',
        'storage/',
        'tests/',
        'scripts/',
        'vendor/',
    ] as $privatePath) {
        $rules[] = 'Disallow: ' . $basePath . '/' . $privatePath;
    }

    if ($baseUrl !== null) {
        $rules[] = 'Sitemap: ' . $baseUrl . '/sitemap.xml';
    }

    return implode("\n", $rules) . "\n";
}

function seoXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
