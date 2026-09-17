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
     * could not be produced at all - the backend failed, or the request is one no
     * artifact is made for. The caller answers with {@see fallback()} then.
     *
     * Write nothing: the caller stores the bytes at $req->filesystemPath (best-effort -
     * a cache that cannot store still delivers them) and serves them. It does not ask
     * at all when $req->sourceFsPath names a file which is not there.
     */
    public function generate(CacheRequest $req): ?string;

    /**
     * What to answer when {@see generate()} returned null: e.g. a blank image of
     * the requested type, or the unminified source for a minifier. Null means
     * there is nothing sensible to send and the caller answers an error status.
     *
     * The caller serves it UNSTORED and only briefly cacheable, never as the
     * artifact - whether the source is gone for good or merely unreadable for a
     * moment (a filesystem refusing PHP) cannot be told apart at this point, and a
     * stored stand-in would outlive the failure: a purge keyed on recency never
     * reclaims a file that is being hit. A source deleted for good therefore costs
     * one cheap request per client-cache expiry, never a stray file forever.
     */
    public function fallback(CacheRequest $req): ?string;

    /**
     * Purge policy for this plugin's cache subtree, overriding the defaults passed
     * to {@see \MissCache\MissCache::purge()} for the keys it declares; return []
     * to accept those defaults unchanged. Keys are the ones understood by
     * {@see CachePurger::purge()}: maxAge, maxBytes, lowWatermark, tmpMaxAge, stale, dryRun.
     *
     * Lets a plugin whose artifacts are cheap to re-forge expire them sooner (and
     * hold less disk) than one whose artifacts are expensive, and - through `stale`
     * - disown artifacts an earlier version wrote and it no longer stands behind.
     *
     * @return array{maxAge?:int,maxBytes?:?int,lowWatermark?:float,tmpMaxAge?:int,stale?:callable,dryRun?:bool}
     */
    public function getPurgeOptions(): array;
}
