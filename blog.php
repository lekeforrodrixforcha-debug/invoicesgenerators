<?php
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/app/seo.php';

$blogPosts = require __DIR__ . '/app/blog_content.php';
$slug = trim((string) ($_GET['slug'] ?? ''));
$post = $slug === '' ? null : ($blogPosts[$slug] ?? null);
$siteHomeUrl = seoAbsoluteUrl();
if ($siteHomeUrl === null) {
    $applicationDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $siteHomeUrl = $applicationDirectory === '/' || $applicationDirectory === '.'
        ? '/'
        : rtrim($applicationDirectory, '/') . '/';
}
$blogText = static function (string $text) use ($siteHomeUrl): string {
    $brandLink = '<a class="blog-brand-link" href="' . e($siteHomeUrl) . '">Invoices Generators</a>';

    return str_replace('Invoices Generators', $brandLink, e($text));
};

if ($slug !== '' && $post === null) {
    http_response_code(404);
}

$isArticle = $post !== null;
$seoPagePath = $isArticle ? 'blog/' . $slug : ($slug === '' ? 'blog' : null);
$seoLocale = 'en_CM';
$htmlLang = 'en';
$seoOpenGraphType = $isArticle ? 'article' : 'website';
$seoArticleUrl = $isArticle ? seoAbsoluteUrl($seoPagePath) : null;
$seoArticleSiteUrl = $isArticle ? seoAbsoluteUrl() : null;
$seoArticleImageUrl = $isArticle ? seoAbsoluteUrl($post['image']) : null;
$siteTitle = $isArticle
    ? $post['title'] . ' | Invoices Generators Blog'
    : ($slug === '' ? 'Invoicing and Small Business Guides | Invoices Generators Blog' : 'Guide not found | Invoices Generators Blog');
$metaDescription = $isArticle
    ? $post['description']
    : ($slug === '' ? 'Practical guides to creating invoices, managing payments and running a small business.' : 'The requested guide could not be found.');
$pageTitle = $siteTitle;
if ($isArticle) {
    $seoStructuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $post['title'],
        'description' => $post['description'],
        'image' => $seoArticleImageUrl,
        'mainEntityOfPage' => $seoArticleUrl,
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Invoices Generators',
            'url' => $seoArticleSiteUrl,
        ],
    ];
    if ($seoArticleUrl === null) {
        unset($seoStructuredData['mainEntityOfPage']);
    }
    if ($seoArticleImageUrl === null) {
        unset($seoStructuredData['image']);
    }
    if ($seoArticleSiteUrl === null) {
        unset($seoStructuredData['publisher']['url']);
    }
}
include __DIR__ . '/includes/header.php';
?>
<?php if ($isArticle): ?>
    <main class="blog-page">
        <article class="blog-article container">
            <a class="blog-back-link" href="blog">← All guides</a>
            <header class="blog-article-header">
                <span class="eyebrow"><?= e($post['category']); ?> <span aria-hidden="true">·</span> <?= e($post['read_time']); ?></span>
                <h1><?= $blogText($post['title']); ?></h1>
                <p class="blog-article-intro"><?= $blogText($post['description']); ?></p>
                <img
                    class="blog-article-image"
                    src="<?= e(publicAssetUrl($post['image'])); ?>"
                    alt="<?= e($post['image_alt']); ?>"
                    width="<?= (int) $post['image_width']; ?>"
                    height="<?= (int) $post['image_height']; ?>"
                    fetchpriority="high"
                    decoding="async">
            </header>
            <div class="blog-article-body">
                <?php if (isset($post['comparison'])): ?>
                    <?php $comparison = $post['comparison']; ?>
                    <section class="blog-comparison" aria-label="Software comparison">
                        <p class="blog-comparison-checked">Features and prices checked <?= e($comparison['checked_on']); ?>.</p>
                        <div class="table-wrap blog-comparison-wrap" role="region" aria-label="<?= e($comparison['caption']); ?>" tabindex="0">
                            <table class="data-table blog-comparison-table">
                                <caption><?= e($comparison['caption']); ?></caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Platform</th>
                                        <th scope="col">Best suited to</th>
                                        <th scope="col">Invoice and quote details</th>
                                        <th scope="col">Published price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($comparison['rows'] as $comparisonRow): ?>
                                        <tr>
                                            <th scope="row">
                                                <?php if ($comparisonRow['source_url'] === null): ?>
                                                    <?= $blogText($comparisonRow['platform']); ?>
                                                    <a class="blog-comparison-source" href="<?= e($siteHomeUrl); ?>#pricing">Local plans</a>
                                                <?php else: ?>
                                                    <a href="<?= e($comparisonRow['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?= e($comparisonRow['platform']); ?></a>
                                                    <a class="blog-comparison-source" href="<?= e($comparisonRow['source_url']); ?>" target="_blank" rel="noopener noreferrer">Official plans</a>
                                                <?php endif; ?>
                                            </th>
                                            <td><?= e($comparisonRow['best_for']); ?></td>
                                            <td><?= e($comparisonRow['features']); ?></td>
                                            <td><?= e($comparisonRow['price']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="blog-comparison-note"><?= e($comparison['price_note']); ?></p>
                        <p class="blog-comparison-note"><?= e($comparison['editorial_note']); ?></p>
                    </section>
                <?php endif; ?>
                <?php foreach ($post['sections'] as $section): ?>
                    <section>
                        <h2><?= $blogText($section['heading']); ?></h2>
                        <?php foreach ($section['paragraphs'] as $paragraph): ?>
                            <p><?= $blogText($paragraph); ?></p>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
                <aside class="blog-disclaimer">
                    <strong>General information only</strong>
                    <p>Business, tax and invoicing requirements vary by location. Confirm requirements for your situation with a qualified local professional.</p>
                </aside>
                <a class="btn btn-primary blog-cta" href="register.php">Create an invoice</a>
            </div>
        </article>
        <?php include __DIR__ . '/includes/blog-ad.php'; ?>
        <section class="blog-related section">
            <div class="container">
                <div class="section-header"><span class="eyebrow">Keep learning</span><h2>More practical guides</h2></div>
                <div class="blog-card-grid">
                    <?php $relatedCount = 0; ?>
                    <?php foreach ($blogPosts as $relatedSlug => $relatedPost): ?>
                        <?php if ($relatedSlug === $slug || $relatedPost['category'] !== $post['category']) { continue; } ?>
                        <?php if ($relatedCount >= 3) { break; } ?>
                        <article class="blog-card">
                            <img class="blog-card-image" src="<?= e(publicAssetUrl($relatedPost['image'])); ?>" alt="<?= e($relatedPost['image_alt']); ?>" width="<?= (int) $relatedPost['image_width']; ?>" height="<?= (int) $relatedPost['image_height']; ?>" loading="lazy" decoding="async">
                            <span class="blog-card-category"><?= e($relatedPost['category']); ?></span>
                            <h3><a href="blog/<?= e($relatedSlug); ?>"><?= $blogText($relatedPost['title']); ?></a></h3>
                            <p><?= $blogText($relatedPost['description']); ?></p>
                            <a class="blog-card-link" href="blog/<?= e($relatedSlug); ?>">Read guide <span aria-hidden="true">↗</span></a>
                        </article>
                        <?php $relatedCount++; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </main>
<?php elseif ($slug === ''): ?>
    <main class="blog-page">
        <section class="blog-hero">
            <div class="container">
                <span class="eyebrow"><span class="eyebrow-dot"></span><?= $blogText('Invoices Generators guides'); ?></span>
                <h1>Practical advice for running your business.</h1>
                <p>Clear, useful guides to invoicing, getting paid and keeping your business finances organized.</p>
            </div>
        </section>
        <section class="section blog-list-section">
            <div class="container">
                <div class="blog-card-grid">
                    <?php foreach ($blogPosts as $postSlug => $blogPost): ?>
                        <article class="blog-card">
                            <img class="blog-card-image" src="<?= e(publicAssetUrl($blogPost['image'])); ?>" alt="<?= e($blogPost['image_alt']); ?>" width="<?= (int) $blogPost['image_width']; ?>" height="<?= (int) $blogPost['image_height']; ?>" loading="lazy" decoding="async">
                            <span class="blog-card-category"><?= e($blogPost['category']); ?> <span aria-hidden="true">·</span> <?= e($blogPost['read_time']); ?></span>
                            <h2><a href="blog/<?= e($postSlug); ?>"><?= $blogText($blogPost['title']); ?></a></h2>
                            <p><?= $blogText($blogPost['description']); ?></p>
                            <a class="blog-card-link" href="blog/<?= e($postSlug); ?>">Read guide <span aria-hidden="true">↗</span></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php include __DIR__ . '/includes/blog-ad.php'; ?>
        <section class="blog-cta-section">
            <div class="container blog-cta-panel">
                <div><span class="eyebrow">Put it into practice</span><h2>Ready to create your next invoice?</h2><p>Make a professional invoice and keep your billing in one place.</p></div>
                <a class="btn btn-primary" href="register.php">Get started</a>
            </div>
        </section>
    </main>
<?php else: ?>
    <main class="blog-page">
        <section class="blog-hero">
            <div class="container">
                <span class="eyebrow">404 · Guide not found</span>
                <h1>We couldn’t find that guide.</h1>
                <p>It may have moved or the address may be incorrect. Browse the latest practical guides instead.</p>
                <a class="btn btn-primary" href="blog">Browse all guides</a>
            </div>
        </section>
    </main>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
