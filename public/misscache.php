<?php

/*
 * Example standalone MissCache dispatcher.
 *
 * Point your web server's "missing file" fallback for the cache directory at
 * this script (see README for the nginx try_files / Apache RewriteCond rules).
 * It runs only on a cache miss; hits are served as static files by the server.
 */

require __DIR__ . '/../vendor/autoload.php';

use MissCache\MissCache;
use MissCache\Plugins\PhpThumbPlugin;

$baseUrl      = '/upload';                                          // public base path of the cache tree
$basePath     = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/upload';  // its location on disk
$cacheSegment = 'mC';
$dirMode      = 0775;

$mC = new MissCache($baseUrl, $basePath, $cacheSegment, $dirMode, [
    new PhpThumbPlugin('https://' . $_SERVER['HTTP_HOST'] . '/img.php'),
]);

if (!$mC->handleRequest($_SERVER['REQUEST_URI'])) {
    http_response_code(404);
    echo 'Not a MissCache URL';
}
