<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $uri;

if ($uri !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/stats/?$#', $uri)) {
    require __DIR__ . '/stats.php';
    return true;
}

if (preg_match('#^/site/([^/]+)/?$#', $uri, $m)) {
    $_GET['host'] = rawurldecode($m[1]);
    require __DIR__ . '/site.php';
    return true;
}

if (preg_match('#^/api(/.*)?$#', $uri)) {
    require __DIR__ . '/api.php';
    return true;
}

if (preg_match('#^/cron/?$#', $uri)) {
    require __DIR__ . '/cron.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
