<?php

namespace MissCache;

use MissCache\Util\PluginRouter;

class MissChache {
    private PluginRouter $router;

    public function __construct(string $basePath, array $plugins ) {
        $this->router = new PluginRouter( $basePath, $plugins );
    }

    public function handleRequest(): bool {
        return $this->router->dispatch();

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
    }
}