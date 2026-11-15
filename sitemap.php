<?php
require_once __DIR__ . '/app/seo.php';

$blogPosts = require __DIR__ . '/app/blog_content.php';
$pages = [
    '' => [
        'file' => __DIR__ . '/index.php',
        'alternates' => [
            'en' => seoAbsoluteUrl(''),
            'fr-CM' => seoAbsoluteUrl('facturation-cameroun'),
            'x-default' => seoAbsoluteUrl(''),
        ],
    ],
    'facturation-cameroun' => [
        'file' => __DIR__ . '/facturation-fr.php',
        'alternates' => [
            'en' => seoAbsoluteUrl(''),
            'fr-CM' => seoAbsoluteUrl('facturation-cameroun'),
            'x-default' => seoAbsoluteUrl(''),
        ],
    ],
    'help' => [
        'file' => __DIR__ . '/help.php',
        'alternates' => [],
    ],
    'blog' => [
        'file' => __DIR__ . '/blog.php',
        'alternates' => [],
    ],
];
foreach (array_keys($blogPosts) as $slug) {
    $pages['blog/' . $slug] = [
        'file' => __DIR__ . '/blog.php',
        'alternates' => [],
    ];
}
$urls = [];
foreach ($pages as $path => $page) {
    $location = seoAbsoluteUrl($path);
    if ($location === null) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Configure a public HTTPS SEO_SITE_URL or APP_BASE_URL to publish the sitemap.';
        exit;
    }

    $modifiedAt = filemtime($page['file']);
    if ($modifiedAt === false) {
        throw new RuntimeException('Unable to read a public page modification time for the sitemap.');
    }
    $lastModified = gmdate(DATE_W3C, $modifiedAt);
    $urls[] = [
        'location' => $location,
        'last_modified' => $lastModified,
        'alternates' => $page['alternates'],
    ];
}

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
foreach ($urls as $url) {
    echo "  <url>\n";
    echo '    <loc>' . seoXmlEscape($url['location']) . "</loc>\n";
    echo '    <lastmod>' . seoXmlEscape($url['last_modified']) . "</lastmod>\n";
    foreach ($url['alternates'] as $language => $alternateUrl) {
        if ($alternateUrl !== null) {
            echo '    <xhtml:link rel="alternate" hreflang="' . seoXmlEscape($language) . '" href="' . seoXmlEscape($alternateUrl) . "\" />\n";
        }
    }
    echo "  </url>\n";
}
echo "</urlset>\n";
