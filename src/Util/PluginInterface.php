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

    /**
     * Purge policy for this plugin's cache subtree, overriding the defaults passed
     * to {@see \MissCache\MissCache::purge()} for the keys it declares; return []
     * to accept those defaults unchanged. Keys are the ones understood by
     * {@see CachePurger::purge()}: maxAge, maxBytes, lowWatermark, tmpMaxAge, dryRun.
     *
     * Lets a plugin whose artifacts are cheap to re-forge expire them sooner (and
     * hold less disk) than one whose artifacts are expensive.
     *
     * @return array{maxAge?:int,maxBytes?:?int,lowWatermark?:float,tmpMaxAge?:int,dryRun?:bool}
     */
    public function getPurgeOptions(): array;
}
