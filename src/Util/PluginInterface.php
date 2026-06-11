<?php

namespace MissCache\Util;

interface PluginInterface
{
    /**
     * Route prefix this plugin handles (the first segment after the cache
     * segment in a cache URL). Example: "pT".
     */
    public function getRoutePrefix(): string;

    /**
     * Generate the cache artifact for $req if missing. Implementations must
     * write the artifact to $req->filesystemPath. Returns true on success
     * (the file exists at that path afterwards), false otherwise.
     */
    public function generate(CacheRequest $req): bool;
}
