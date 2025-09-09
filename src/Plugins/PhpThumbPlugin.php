<?php

namespace MissCache\Plugins;

use MissCache\CacheRequest;
use MissCache\PluginInterface;

final class PhpThumbPlugin implements PluginInterface
{
    public function __construct(
        private string $phpThumbEntry,   // e.g. /var/www/app/aaa/img.php
    ) {}

    public function getRoutePrefix(): string
    {
        return 'phpThumbCache';
    }

    public function generate(CacheRequest $req): bool
    {
        $route    = CacheRoute::fromRouteRemainder($req->routeRemainder());
        $rawQuery = $route->toRawQueryString(true); // "src=/img_upload/...&..."

        $target = $req->filesystemPath();
        $dir    = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        // Call phpThumb entry and capture the image
        $url = $this->phpThumbEntry.'?'.$rawQuery;

        // Option A: include/ob capture (if img.php works as a library)
        ob_start();
        $_SERVER['QUERY_STRING'] = $rawQuery;
        parse_str($rawQuery, $_GET);
        include $this->phpThumbEntry;  // must echo binary
        $data = ob_get_clean();

        if (!is_string($data) || $data === '') {
            return false;
        }
        if (file_put_contents($target, $data) === false) {
            return false;
        }

        return is_file($target);
    }
}