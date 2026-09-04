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
     * Generate the cache artifact for $req and return its bytes, or null if it
     * could not be produced at all.
     *
     * Implementations SHOULD also store the artifact at $req->filesystemPath so
     * later requests are served statically, but storing is explicitly allowed to
     * fail: returning the bytes is the contract, writing them is the optimisation.
     * A cache that cannot store must still deliver — the caller serves whatever
     * comes back here even when nothing reached the disk (full disk, read-only
     * mount, a name over the filesystem's NAME_MAX, ...), so such a condition
     * costs performance and never a broken image.
     *
     * Returning null means the artifact itself is unavailable, which the caller
     * answers with an error status.
     */
    public function generate(CacheRequest $req): ?string;

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
