<?php
require_once __DIR__ . '/../app/seo.php';

$siteTitle = $siteTitle ?? 'Invoices Generators';
$metaDescription = $metaDescription ?? 'Invoices Generators helps freelancers and businesses create professional invoices and manage cash flow in one workspace.';
$isLoggedIn = !empty($_SESSION['user_id']);
$currentLayoutUser = $isLoggedIn ? getCurrentUser($db) : null;
$isAdminUser = ($currentLayoutUser['role'] ?? 'user') === 'admin';
$activeNav = $activeNav ?? 'dashboard';
$seoPagePath = $seoPagePath ?? null;
$seoLocale = $seoLocale ?? 'en_CM';
$seoCanonicalUrl = seoIndexablePage($seoPagePath, $isLoggedIn) ? seoAbsoluteUrl((string) $seoPagePath) : null;
$seoRobots = seoIndexablePage($seoPagePath, $isLoggedIn) ? 'index, follow' : 'noindex, follow';
$seoSocialImage = $seoCanonicalUrl !== null ? seoAbsoluteUrl('public/images/invoices-generators-social.png') : null;
if ($seoSocialImage !== null) {
    $seoSocialImage .= '?v=' . publicAssetVersion('public/images/invoices-generators-social.png');
}
$seoAlternates = $seoAlternates ?? [];
$seoStructuredData = $seoStructuredData ?? [];
?>
<!DOCTYPE html>
<html lang="<?= e($htmlLang ?? 'en'); ?>" class="<?= e($bodyClass ?? ''); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($metaDescription); ?>">
    <meta name="robots" content="<?= e($seoRobots); ?>">
    <meta name="theme-color" content="#6b55dc">
    <link rel="icon" type="image/svg+xml" href="<?= e(publicAssetUrl('public/images/favicon.svg')); ?>">
    <link rel="shortcut icon" href="<?= e(publicAssetUrl('public/images/favicon.svg')); ?>">
    <?php if ($seoPagePath === 'blog' || ($seoPagePath !== null && str_starts_with($seoPagePath, 'blog/'))): ?>
        <?php $siteRootPath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/'); ?>
        <base href="<?= e(($siteRootPath === '' ? '' : $siteRootPath) . '/'); ?>">
    <?php endif; ?>
    <?php if ($isLoggedIn): ?><meta name="csrf-token" content="<?= e(csrfToken()); ?>"><?php endif; ?>
    <title><?= e($siteTitle); ?></title>
    <?php if ($seoCanonicalUrl !== null): ?>
        <link rel="canonical" href="<?= e($seoCanonicalUrl); ?>">
        <meta property="og:type" content="<?= e($seoOpenGraphType ?? 'website'); ?>">
        <meta property="og:site_name" content="Invoices Generators">
        <meta property="og:title" content="<?= e($siteTitle); ?>">
        <meta property="og:description" content="<?= e($metaDescription); ?>">
        <meta property="og:url" content="<?= e($seoCanonicalUrl); ?>">
        <meta property="og:locale" content="<?= e($seoLocale); ?>">
        <?php if ($seoSocialImage !== null): ?>
            <meta property="og:image" content="<?= e($seoSocialImage); ?>">
            <meta property="og:image:secure_url" content="<?= e($seoSocialImage); ?>">
            <meta property="og:image:type" content="image/png">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="Invoices Generators invoice workspace for Cameroon businesses">
        <?php endif; ?>
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="<?= e($siteTitle); ?>">
        <meta name="twitter:description" content="<?= e($metaDescription); ?>">
        <?php if ($seoSocialImage !== null): ?><meta name="twitter:image" content="<?= e($seoSocialImage); ?>"><?php endif; ?>
        <?php foreach ($seoAlternates as $language => $alternatePath): ?>
            <?php $alternateUrl = seoAbsoluteUrl((string) $alternatePath); ?>
            <?php if ($alternateUrl !== null): ?><link rel="alternate" hreflang="<?= e((string) $language); ?>" href="<?= e($alternateUrl); ?>"><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($seoStructuredData !== []): ?>
            <script type="application/ld+json"><?= json_encode($seoStructuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?></script>
        <?php endif; ?>
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(publicAssetUrl('public/css/styles.css')); ?>">
</head>
<body class="<?= e($bodyClass ?? ''); ?>">
<?php if ($isLoggedIn): ?>
    <div class="app-shell">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <main class="main-panel">
            <?php include __DIR__ . '/topbar.php'; ?>
<?php else: ?>
    <div class="site-shell">
        <header class="site-header">
            <div class="container nav-wrap">
                <a href="index.php" class="brand-link">
                    <span class="brand-mark">I</span>
                    <span>Invoices Gen</span>
                </a>
                <nav class="site-nav">
                    <?php if ($seoPagePath === 'facturation-cameroun'): ?>
                        <a href="index.php">Accueil</a>
                        <a href="index.php#features">Fonctionnalités</a>
                        <a href="index.php#templates">Modèles</a>
                        <a href="index.php#pricing">Tarifs</a>
                        <a href="blog">Blog</a>
                        <a href="register.php" class="btn btn-primary">Commencer</a>
                    <?php elseif ($seoPagePath === 'index.php' || $seoPagePath === ''): ?>
                        <a href="index.php">Home</a>
                        <a href="index.php#features">Features</a>
                        <a href="index.php#templates">Templates</a>
                        <a href="index.php#pricing">Pricing</a>
                        <a href="blog">Blog</a>
                        <a href="help.php">Help</a>
                        <a href="login.php">Login</a>
                        <a href="register.php" class="btn btn-primary">Get Started</a>
                    <?php else: ?>
                        <a href="index.php">Home</a>
                        <a href="index.php#features">Features</a>
                        <a href="index.php#templates">Templates</a>
                        <a href="index.php#pricing">Pricing</a>
                        <a href="blog">Blog</a>
                        <a href="help.php">Help</a>
                        <a href="login.php">Login</a>
                        <a href="register.php" class="btn btn-primary">Get Started</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>
<?php endif; ?>
