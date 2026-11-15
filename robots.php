<?php
require_once __DIR__ . '/app/seo.php';

header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo seoRobotsTxt();
