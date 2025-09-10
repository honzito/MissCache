<?php

use MissCache\Util\Util\Util\PluginRouter;

require __DIR__ . '/../vendor/autoload.php';

$documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$requestUri   = $_GET['fpath'] ?? '';        // e.g. "/phpThumbCache/img_upload/xy/..."
$cacheRootUri = '/';                          // not used directly; kept for clarity

$mC = new MissCache\MissChache( [new PhpThumbPlugin($documentRoot . '/aaa/img.php')] );
// new SpatieImagePlugin()

$router = new PluginRouter(
    new PhpThumbPlugin($documentRoot . '/aaa/img.php'),
    // new SpatieImagePlugin()
);

$req = new CacheRequest($documentRoot, $cacheRootUri, $requestUri);

if ($router->dispatch($req)) {
    // File generated; stream it
    $path = $req->filesystemPath();
    header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
    readfile($path);
    exit;
}

http_response_code(500);
echo 'Generation failed';